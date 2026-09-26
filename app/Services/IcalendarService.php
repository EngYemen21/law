<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Support\MeetingTime;
use App\Support\SessionWindow;
use App\Support\SettingsRegistry;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * خدمة معمارية نظيفة لتوليد ملفات iCalendar (RFC 5545) واشتراكات التقويم الحي (Live Feed).
 *
 * معيار مفتوح لا يخصّ مزوّداً بعينه: أيّ برنامج تقويم يقرأ الناتج. أُزيل منها ما كان
 * خاصّاً بجوجل (رابط الإضافة السريع وترميز الأحداث للبريد) بقرار المالك 2026-09-20.
 */
class IcalendarService
{
    /**
     * نافذة تغذية الطاقم الذي يرى المكتب كلّه (الموظف/الإدارة) — تطابق شاشة تقويم الموظف
     * (Employee\CalendarController) كي لا يُبنى تقويم اشتراك بلا حدّ من أرشيف المكتب كلّه.
     */
    private const FEED_PAST_DAYS = 7;

    private const FEED_FUTURE_DAYS = 90;

    private const FEED_MAX_EVENTS = 300;

    /**
     * توليد ملف VCALENDAR لحدث فردي (مرفق البريد الإلكتروني).
     */
    public static function generate(
        string $uid,
        string $title,
        string $description,
        DateTimeInterface|CarbonInterface $startsAt,
        int $durationMinutes,
        string $locationUrl,
        ?string $organizerName = null,
        ?string $organizerEmail = null
    ): string {
        // وبريد المنظِّم كذلك (`office_email`) — كان `no-reply@salasel.sa` منقوشاً في التوقيع
        $organizerEmail ??= SettingsRegistry::str('office_email');
        // الافتراض اسم المكتب من الإعدادات لا نصّاً منقوشاً (والافتراض الساكن في التوقيع لا يقبل نداءً)
        $organizerName ??= SettingsRegistry::str('office_name');
        $startCarbon = Carbon::parse($startsAt)->utc();
        $startUtc = $startCarbon->format('Ymd\THis\Z');
        $endUtc = $startCarbon->copy()->addMinutes(max(15, $durationMinutes))->format('Ymd\THis\Z');
        $stampUtc = now()->utc()->format('Ymd\THis\Z');

        $cleanTitle = self::escape($title);
        $cleanDesc = self::escape($description);
        $cleanLoc = self::escape($locationUrl);

        return "BEGIN:VCALENDAR\r\n"
            ."VERSION:2.0\r\n"
            ."PRODID:-//LegalOffice//Management System//AR\r\n"
            ."CALSCALE:GREGORIAN\r\n"
            ."METHOD:REQUEST\r\n"
            ."BEGIN:VEVENT\r\n"
            ."UID:{$uid}@salasel.sa\r\n"
            ."DTSTAMP:{$stampUtc}\r\n"
            ."ORGANIZER;CN=\"{$organizerName}\":mailto:{$organizerEmail}\r\n"
            ."DTSTART:{$startUtc}\r\n"
            ."DTEND:{$endUtc}\r\n"
            ."SUMMARY:{$cleanTitle}\r\n"
            ."DESCRIPTION:{$cleanDesc}\r\n"
            ."LOCATION:{$cleanLoc}\r\n"
            ."STATUS:CONFIRMED\r\n"
            ."SEQUENCE:0\r\n"
            ."BEGIN:VALARM\r\n"
            ."TRIGGER:-PT15M\r\n"
            ."ACTION:DISPLAY\r\n"
            ."DESCRIPTION:تنبيه بموعد الجلسة قبل 15 دقيقة\r\n"
            ."END:VALARM\r\n"
            ."END:VEVENT\r\n"
            ."END:VCALENDAR\r\n";
    }

    /**
     * تغذية تقويم حية (Live Subscription Feed) تجمع جميع مواعيد وجلسات واستشارات المستخدم.
     */
    public static function feedForUser(User $user): string
    {
        $events = collect();

        // ثلاثة مستويات رؤية لا اثنان: المحامي معزول بإسناده، والعميل بسجلّاته، أمّا الموظف
        // والإدارة فيريان المكتب كلّه. كان الثلاثة (طاقم) يُفلترون بـassigned_lawyer_id، والموظف
        // والإدارة لا يكونان مُسنَدَين أبداً (العمود للمحامين) ⇒ تغذية فارغة تماماً بينما شاشة
        // تقويمهما تعرض أحداث المكتب كلّها وتعرض زرّ الاشتراك.
        $isLawyer = $user->role === Role::Lawyer;
        $isOfficeWide = in_array($user->role, [Role::Employee, Role::Admin], true);
        $from = now()->subDays(self::FEED_PAST_DAYS);
        $to = now()->addDays(self::FEED_FUTURE_DAYS);

        // نافذة المكتب — تُبقي الصفوف بلا موعد محدّد (تُجدول لاحقاً) كما تفعل شاشة التقويم
        $window = fn ($query) => $query
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhereBetween('starts_at', [$from, $to]))
            ->orderByRaw('starts_at is null')->orderBy('starts_at')
            ->limit(self::FEED_MAX_EVENTS);

        /*
         * **`DTEND` اسميّ للاستشارة والاجتماع والموعد** (`SessionWindow::nominalMinutes`) — iCalendar
         * يشترطه، والجلسة لا مدّة لها: تنتهي حين تُنهى (قرار المالك 2026-09-26). فالرقم يحجز خانةً
         * في تقويم القارئ بطول شريحة الحجز، ولا يقرؤه منطق الانتهاء في المنصّة.
         */
        // 1. الاستشارات
        $consultQuery = Consult::with('appointment');
        if ($isLawyer) {
            $consultQuery->where('assigned_lawyer_id', $user->id);
        } elseif ($isOfficeWide) {
            $window($consultQuery);
        } else {
            $consultQuery->where('user_id', $user->id);
        }
        $consults = $consultQuery->whereNotIn('status', ['ملغاة'])->get();

        foreach ($consults as $c) {
            $start = $c->starts_at ?: MeetingTime::parse($c->day ?? '', $c->time ?? '');
            if (! $start) {
                continue;
            }
            $link = $c->joinLink($user);
            $events->push(self::formatVEvent(
                uid: 'CONSULT-'.$c->id,
                title: "استشارة: {$c->subject} ({$c->ref})",
                // تقويم العميل يُقرأ في تطبيقه خارج المنصّة — فالمستشار باسمه للعميل لا الكامل
                description: "استشارة قانونية ({$c->channel})\nالمستشار: ".($user->isClient() ? $c->lawyerForClient() : $c->lawyer)."\nرابط الجلسة: {$link}",
                startsAt: $start,
                durationMinutes: SessionWindow::nominalMinutes(),
                location: $c->channel === 'حضورية' ? $c->placeLabel() : $link
            ));
        }

        // 2. الاجتماعات
        $meetingQuery = Meeting::query();
        if ($isLawyer) {
            $meetingQuery->where('assigned_lawyer_id', $user->id);
        } elseif ($isOfficeWide) {
            $window($meetingQuery);
        } else {
            $meetingQuery->where('user_id', $user->id);
        }
        $meetings = $meetingQuery->whereNotIn('status', ['ملغى'])->get();

        foreach ($meetings as $m) {
            $start = $m->starts_at ?: now();
            $link = $m->joinLink($user);
            $events->push(self::formatVEvent(
                uid: 'MEET-'.$m->id,
                title: "اجتماع: {$m->title} ({$m->ref})",
                description: "اجتماع رسمي عبر المنصة\nرابط الاجتماع: {$link}",
                startsAt: $start,
                durationMinutes: SessionWindow::nominalMinutes(),
                location: $link
            ));
        }

        // 3. جلسات المحاكم
        $hearingQuery = CaseHearing::query()->with('legalCase');
        if ($isLawyer) {
            $hearingQuery->whereHas('legalCase', fn ($q) => $q->where('assigned_lawyer_id', $user->id));
        } elseif ($isOfficeWide) {
            $window($hearingQuery);
        } else {
            $hearingQuery->whereHas('legalCase', fn ($q) => $q->where('user_id', $user->id));
        }
        $hearings = $hearingQuery->get();

        /*
         * **جلسة المحكمة بلا نهاية مختلَقة** (قرار المالك 2026-09-26) — كانت ستّين دقيقة منقوشة.
         * المدّة المتوقّعة يُدخلها الطاقم إن عرفها فيُكتب `DTEND` منها (`CaseHearing::endsAt`)؛ وإلا
         * لا `DTEND` أصلاً: RFC 5545 (3.6.1) يجعل حدثاً بـ`DTSTART` زمنيّ بلا نهايةٍ حدثاً عند لحظة
         * بدايته — وهو الصادق: نعرف متى تبدأ الجلسة ولا نعرف متى تنتهي. ولا رقم اسميّ هنا كالاستشارة:
         * تلك شريحة حجزٍ يعرفها المكتب، وهذه موعدٌ تحدّده المحكمة.
         */
        foreach ($hearings as $h) {
            $start = $h->startMoment();
            if (! $start) {
                continue;
            }
            $events->push(self::formatVEvent(
                uid: 'HEARING-'.$h->id,
                title: "جلسة محكمة: {$h->title} (قضية ".($h->legalCase?->number ?: '—').')',
                description: "جلسة قضائية\nالمحكمة: ".($h->court ?: 'المحكمة المختصة')."\nرقم القضية: ".($h->legalCase?->number ?: '—')
                    .($h->duration_min ? "\nالمدّة المتوقّعة: {$h->duration_min} دقيقة" : ''),
                startsAt: $start,
                durationMinutes: $h->duration_min,
                location: $h->court ?: 'المحكمة المختصة'
            ));
        }

        // 4. المواعيد الحضورية/المكتبية
        $appts = Appointment::where('user_id', $user->id)->where('status', '!=', 'بانتظار الاعتماد')->get(); // الاقتراح غير المعتمد لا يدخل تقويم العميل
        foreach ($appts as $a) {
            $start = MeetingTime::parse($a->day, $a->time);
            if (! $start) {
                continue;
            }
            $events->push(self::formatVEvent(
                uid: 'APPT-'.$a->id,
                title: "موعد: {$a->type}",
                description: "موعد رسمي لدى المكتب\nالمحامي: ".($user->isClient() ? $a->lawyerForClient() : $a->lawyer)."\nالمكان: {$a->place}",
                startsAt: $start,
                durationMinutes: SessionWindow::nominalMinutes(),
                location: $a->place ?: SettingsRegistry::str('office_address')
            ));
        }

        $vEventsBody = $events->implode("\r\n");

        return "BEGIN:VCALENDAR\r\n"
            ."VERSION:2.0\r\n"
            ."PRODID:-//LegalOffice//Management System Calendar Feed//AR\r\n"
            ."CALSCALE:GREGORIAN\r\n"
            .'X-WR-CALNAME:مواعيد '.self::escape(SettingsRegistry::str('office_name'))."\r\n"
            ."X-WR-TIMEZONE:Asia/Riyadh\r\n"
            ."REFRESH-INTERVAL;VALUE=DURATION:PT1H\r\n"
            ."X-PUBLISHED-TTL:PT1H\r\n"
            .($vEventsBody !== '' ? $vEventsBody."\r\n" : '')
            ."END:VCALENDAR\r\n";
    }

    private static function formatVEvent(
        string $uid,
        string $title,
        string $description,
        DateTimeInterface|CarbonInterface $startsAt,
        ?int $durationMinutes,
        string $location
    ): string {
        $startCarbon = Carbon::parse($startsAt)->utc();
        $startUtc = $startCarbon->format('Ymd\THis\Z');
        // null ⇒ لا `DTEND`: حدثٌ عند لحظة بدايته (جلسة محكمة بلا مدّةٍ مُدخلة) بدل نهايةٍ مختلَقة.
        // وبلا أرضيّة ١٥ دقيقة: مدّة الجلسة المُدخلة تُكتب كما هي، والرقم الاسميّ للاستشارة أرضيّته
        // ١٥ في الإعدادات أصلاً (`consult_slot_minutes`)
        $endLine = $durationMinutes === null
            ? ''
            : 'DTEND:'.$startCarbon->copy()->addMinutes($durationMinutes)->format('Ymd\THis\Z')."\r\n";
        $stampUtc = now()->utc()->format('Ymd\THis\Z');

        $cleanTitle = self::escape($title);
        $cleanDesc = self::escape($description);
        $cleanLoc = self::escape($location);

        return "BEGIN:VEVENT\r\n"
            ."UID:{$uid}@salasel.sa\r\n"
            ."DTSTAMP:{$stampUtc}\r\n"
            ."DTSTART:{$startUtc}\r\n"
            .$endLine
            ."SUMMARY:{$cleanTitle}\r\n"
            ."DESCRIPTION:{$cleanDesc}\r\n"
            ."LOCATION:{$cleanLoc}\r\n"
            ."STATUS:CONFIRMED\r\n"
            ."BEGIN:VALARM\r\n"
            ."TRIGGER:-PT15M\r\n"
            ."ACTION:DISPLAY\r\n"
            ."DESCRIPTION:تنبيه بموعد الجلسة\r\n"
            ."END:VALARM\r\n"
            .'END:VEVENT';
    }

    private static function escape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(';', '\;', $value);
        $value = str_replace(',', '\,', $value);
        $value = str_replace(["\r\n", "\n", "\r"], '\\n', $value);

        return $value;
    }
}
