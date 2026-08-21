<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Support\MeetingTime;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * خدمة معمارية نظيفة لتوليد ملفات iCalendar (RFC 5545)
 * وروابط المزامنة الفورية مع Google Calendar واشتراكات التقويم الحي (Live Feed).
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
     * ترميز Google Schema.org JSON-LD المعتمد للأحداث (للإدراج التلقائي الصامت في تقويم جوجل فور وصول البريد).
     */
    public static function googleSchemaJsonLd(
        string $reservationNumber,
        string $recipientName,
        string $title,
        string $description,
        DateTimeInterface|CarbonInterface $startsAt,
        int $durationMinutes,
        string $locationUrl
    ): string {
        $start = Carbon::parse($startsAt)->toIso8601String();
        $end = Carbon::parse($startsAt)->copy()->addMinutes(max(15, $durationMinutes))->toIso8601String();

        $data = [
            '@context' => 'http://schema.org',
            '@type' => 'EventReservation',
            'reservationNumber' => $reservationNumber,
            'reservationStatus' => 'http://schema.org/Confirmed',
            'underName' => [
                '@type' => 'Person',
                'name' => $recipientName,
            ],
            'reservationFor' => [
                '@type' => 'Event',
                'name' => $title,
                'startDate' => $start,
                'endDate' => $end,
                'description' => $description,
                'location' => [
                    '@type' => 'VirtualLocation',
                    'name' => 'غرفة الاجتماعات بالمنصة',
                    'url' => $locationUrl,
                ],
            ],
        ];

        return '<script type="application/ld+json">'.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'</script>';
    }

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
        string $organizerName = 'النظام الإداري لمكاتب المحاماة',
        string $organizerEmail = 'no-reply@salasel.sa'
    ): string {
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
     * رابط إضافة فوري لتقويم جوجل (بتوقيت ISO دقيق بالـ UTC).
     */
    public static function googleUrl(
        string $title,
        string $details,
        DateTimeInterface|CarbonInterface|null $startsAt,
        int $durationMinutes,
        string $locationUrl
    ): string {
        $startCarbon = Carbon::parse($startsAt ?: now())->utc();
        $start = $startCarbon->format('Ymd\THis\Z');
        $end = $startCarbon->copy()->addMinutes(max(15, $durationMinutes))->format('Ymd\THis\Z');

        $fullDetails = trim($details."\n\nرابط الجلسة بالمنصة: ".$locationUrl);

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => "{$start}/{$end}",
            'details' => $fullDetails,
            'location' => $locationUrl,
        ]);
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
            $dur = $c->duration_min ?: 45;
            $link = $c->joinLink($user);
            $events->push(self::formatVEvent(
                uid: 'CONSULT-'.$c->id,
                title: "استشارة: {$c->subject} ({$c->ref})",
                description: "استشارة قانونية ({$c->channel})\nالمستشار: {$c->lawyer}\nرابط الجلسة: {$link}",
                startsAt: $start,
                durationMinutes: $dur,
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
                durationMinutes: 60,
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

        foreach ($hearings as $h) {
            $start = MeetingTime::parse($h->day, $h->time);
            if (! $start) {
                continue;
            }
            $events->push(self::formatVEvent(
                uid: 'HEARING-'.$h->id,
                title: "جلسة محكمة: {$h->title} (قضية ".($h->legalCase?->number ?: '—').')',
                description: "جلسة قضائية\nالمحكمة: ".($h->court ?: 'المحكمة المختصة')."\nرقم القضية: ".($h->legalCase?->number ?: '—'),
                startsAt: $start,
                durationMinutes: 60,
                location: $h->court ?: 'المحكمة المختصة'
            ));
        }

        // 4. المواعيد الحضورية/المكتبية
        $appts = Appointment::where('user_id', $user->id)->get();
        foreach ($appts as $a) {
            $start = MeetingTime::parse($a->day, $a->time);
            if (! $start) {
                continue;
            }
            $events->push(self::formatVEvent(
                uid: 'APPT-'.$a->id,
                title: "موعد: {$a->type}",
                description: "موعد رسمي لدى المكتب\nالمحامي: {$a->lawyer}\nالمكان: {$a->place}",
                startsAt: $start,
                durationMinutes: 30,
                location: $a->place ?: (string) config('office.address')
            ));
        }

        $vEventsBody = $events->implode("\r\n");

        return "BEGIN:VCALENDAR\r\n"
            ."VERSION:2.0\r\n"
            ."PRODID:-//LegalOffice//Management System Calendar Feed//AR\r\n"
            ."CALSCALE:GREGORIAN\r\n"
            ."X-WR-CALNAME:مواعيد النظام الإداري لمكاتب المحاماة\r\n"
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
        int $durationMinutes,
        string $location
    ): string {
        $startCarbon = Carbon::parse($startsAt)->utc();
        $startUtc = $startCarbon->format('Ymd\THis\Z');
        $endUtc = $startCarbon->copy()->addMinutes(max(15, $durationMinutes))->format('Ymd\THis\Z');
        $stampUtc = now()->utc()->format('Ymd\THis\Z');

        $cleanTitle = self::escape($title);
        $cleanDesc = self::escape($description);
        $cleanLoc = self::escape($location);

        return "BEGIN:VEVENT\r\n"
            ."UID:{$uid}@salasel.sa\r\n"
            ."DTSTAMP:{$stampUtc}\r\n"
            ."DTSTART:{$startUtc}\r\n"
            ."DTEND:{$endUtc}\r\n"
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
