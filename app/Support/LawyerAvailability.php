<?php

namespace App\Support;

use App\Domain\Journey\Enums\AppointmentStatus;
use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\HearingStatus;
use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\BusyKind;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * محرّك التفرّغ والاقتراح لحجز الاستشارة:
 * - يرشّح المحامين بنفس التخصّص ويرتّبهم حتميّاً بسجلّ النجاح والحمل (فوري، بلا نداء AI متزامن).
 * - يحسب سجلّ النجاح لكل محامٍ (نسبة/عدد المغلق من التذاكر والقضايا والتنفيذ).
 * - يولّد فترات المواعيد المتاحة ويكشف الانشغال لمنع الحجز المزدوج.
 * كل المنطق حتميّ ويعمل بلا ذكاء اصطناعي (اختبارات/غياب مزوّد).
 */
class LawyerAvailability
{
    /*
     * **افتراضاتٌ مُعلَنة لا قيمٌ نافذة.** ساعات الحجز وطول الشريحة إعداداتٌ تضبطها الإدارة
     * (`consult_day_start`/`consult_day_end`/`consult_slot_minutes` في `SettingsRegistry`)،
     * والسجلّ يأخذ افتراضه من هنا. القراءة من `workHours()` و`slotMinutes()` وحدهما.
     */
    public const WORK_START = 9;   // دوام المكتب (قرار المالك 2026-09-28): من 09:00

    public const WORK_END = 22;    // إلى 22:00 — آخر بداية 21:00 لشريحة 60د

    /** أيّام الدوام الافتراضيّة بتقويم Carbon (الأحد=0): الأحد–الخميس. الإعداد `consult_work_days`. */
    public const WORK_DAYS = [0, 1, 2, 3, 4];

    public const SLOT_MIN = 60;    // مسافة الحجز الافتراضيّة — لا عمر الجلسة (قرار المالك 2026-09-26)

    /**
     * حالات «مغلق» لكل نوع — تُغذّي **معدّل الإغلاق** لا معدّل النجاح.
     *
     * ⚠️ الفارق ليس لفظياً: `'صدر الحكم'` حالةُ إغلاقٍ إجرائيّة تُحتسب **بصرف النظر
     * عن اتّجاه الحكم** — لصالح الموكّل أو ضدّه. فالنسبة تقيس «كم ملفّاً أُغلق»
     * لا «كم ملفّاً كُسب». وكانت تُعرض للعميل بعنوان «معدّل الإنجاز» وهو يختار
     * محاميه بناءً عليها، فيقرؤها سجلَّ كفاءةٍ قضائيّة وهي مقياسٌ تشغيليّ.
     *
     * ولا يُشتقّ معدّل كسبٍ حقيقيّ من البيانات القائمة: لا حقل يسجّل لمن صدر الحكم.
     * فالصدق أن تُسمّى بما تقيس، لا أن تُخمَّن نسبةٌ لا تُحسب.
     */
    private const CLOSED_CASES = ['صدر الحكم', 'مغلقة', 'مؤرشفة'];

    /** من مصدرٍ واحد: الحالات المغلقة للتنفيذ (`Execution::CLOSED_STATUSES`). */
    private const CLOSED_EXECS = Execution::CLOSED_STATUSES;

    /**
     * المحامون المتخصّصون مرتّبون بأولوية الذكاء الاصطناعي، مع سجلّ النجاح والتفرّغ ليوم مُعطى.
     *
     * @return array<int, array{id:int,name:string,dept:string,success:array,load:int,slots:array,freeCount:int}>
     */
    public static function rankedSpecialists(string $specialty, ?string $subject, ?string $date = null): array
    {
        $lawyers = User::where('role', Role::Lawyer)->where('status', 'active')->with('specialties')->get();
        if ($lawyers->isEmpty()) {
            return [];
        }

        // مطابقة التخصّص إن وُجد مطابقون، وإلا فكل المحامين — تخصّصاته في الكتالوج لا نصّ قسمه وحده
        $matched = $lawyers->filter(fn (User $u) => LawyerSpecialties::covers($u, null, $specialty))->values();
        $pool = $matched->isNotEmpty() ? $matched : $lawyers;
        $ids = $pool->pluck('id')->map(fn ($i) => (int) $i)->all();

        // تجميع دفعة واحدة (بدل ~8 استعلامات لكل محامٍ): حالات التذاكر/القضايا/التنفيذ لكل الپول
        $tickets = Ticket::whereIn('assigned_lawyer_id', $ids)->get(['assigned_lawyer_id', 'status'])->groupBy('assigned_lawyer_id');
        $cases = LegalCase::whereIn('assigned_lawyer_id', $ids)->get(['assigned_lawyer_id', 'status'])->groupBy('assigned_lawyer_id');
        $execs = Execution::whereIn('assigned_lawyer_id', $ids)->get(['assigned_lawyer_id', 'status'])->groupBy('assigned_lawyer_id');

        // انشغال اليوم لكل الپول **دفعةً واحدة** — من المصدر الموحّد نفسه
        // الذي يقرأ منه `slotsFor`. وكان يقرأ المواعيد وحدها، فتعرض شبكة
        // العميل ساعةً يحجبها المحرّك — وهو أخطر موضعٍ للتباين لأنّه الذي يراه العميل.
        $day = self::resolveDate($date);
        $isWorkDay = self::isWorkDay($day);
        $intervalsByLawyer = $isWorkDay
            ? self::busyIntervalsForMany($ids, $day->toDateString())
            : [];

        // وسم كل مرشّح بسجلّ النجاح والحمل من البيانات المجمّعة (بلا استعلام لكل محامٍ)
        $rows = $pool->map(function ($u) use ($tickets, $cases, $execs) {
            $id = (int) $u->id;
            $t = $tickets->get($id, collect());
            $c = $cases->get($id, collect());
            $e = $execs->get($id, collect());
            $total = $t->count() + $c->count() + $e->count();
            $closed = $t->whereIn('status', TicketStatus::finals())->count()
                + $c->whereIn('status', self::CLOSED_CASES)->count()
                + $e->whereIn('status', self::CLOSED_EXECS)->count();

            return [
                'id' => $id,
                'name' => $u->name,
                'dept' => $u->department ?: '—',
                'success' => [
                    'rate' => $total > 0 ? (int) round($closed / $total * 100) : 0,
                    'closed' => $closed,
                    'total' => $total,
                ],
                'load' => $t->whereNotIn('status', TicketStatus::finals())->count(),
            ];
        })->sort(fn ($a, $b) => [$b['success']['closed'], $b['success']['rate'], $a['load'], $a['id']]
            <=> [$a['success']['closed'], $a['success']['rate'], $b['load'], $b['id']]
        )->values();

        // ترتيب حتميّ فوري (الأكثر إنجازاً ← الأقل حملاً) — بلا نداء AI متزامن على نقطة تفاعلية
        // (كان rankLawyers يعلّق طلب اختيار الموعد حتى 150ث؛ الترتيب الحتميّ سريع وسليم).
        return $rows->map(function (array $row) use ($intervalsByLawyer, $isWorkDay, $day) {
            $slots = $isWorkDay
                ? self::slotsFromIntervals($intervalsByLawyer[$row['id']] ?? [], $day)
                : [];
            $row['slots'] = $slots;
            $row['freeCount'] = count(array_filter($slots, fn ($s) => ! $s['taken']));

            return $row;
        })->all();
    }

    /**
     * **ما يراه العميل من التفرّغ: الأوقات وحدها.**
     *
     * `rankedSpecialists` مخرَجٌ داخليّ: اسم كلّ محامٍ وقسمه وحمله ونسبة إنجاز ملفّاته. وكان
     * يُرسَل كما هو إلى واجهتَي العميل (`/book/availability` و`/tickets/{ticket}/availability`)،
     * فيقرأ أيّ عميلٍ أداءَ المكتب وأسماء محاميه كاملةً — والعميل **لا يختار المحامي أصلاً**
     * (`assignLawyer` تُسنده بعد تأكيد الحجز)، فالبيانات كلّها زائدة عن حاجته.
     *
     * فالمخرَج هنا: ساعات اليوم، وكلّ ساعةٍ متاحةٌ إن كان فيها مختصٌّ واحد على الأقلّ غير مشغول،
     * و`advisors` عددُ المتاحين في اليوم — عددٌ لا هويّات.
     *
     * @return array{slots: array<int, array{time:string, taken:bool}>, advisors: int}
     */
    public static function clientSlots(string $specialty, ?string $subject, ?string $date = null): array
    {
        $ranked = self::rankedSpecialists($specialty, $subject, $date);

        $byTime = [];
        foreach ($ranked as $lawyer) {
            foreach ($lawyer['slots'] as $slot) {
                $time = (string) $slot['time'];
                // الساعة متاحةٌ إن أتاحها أحدهم — والعميل لا يعرف مَن
                $byTime[$time] = ($byTime[$time] ?? false) || ! $slot['taken'];
            }
        }
        ksort($byTime);

        return [
            'slots' => array_values(array_map(
                fn (string $time) => ['time' => $time, 'taken' => ! $byTime[$time]],
                array_keys($byTime)
            )),
            'advisors' => count(array_filter($ranked, fn (array $l) => $l['freeCount'] > 0)),
        ];
    }

    /**
     * الإسناد التلقائيّ (العميل لا يختار): أعلى مختصّ في نوع المشكلة (تخصّص + سجلّ إنجاز)
     * غير مشغول في الوقت المطلوب. يعيد المحامي، أو null إن لم يتوفّر أيّ مختصّ في تلك الفترة.
     */
    public static function assignLawyer(string $specialty, ?string $subject, Carbon $startsAt, ?int $dur = null): ?User
    {
        $dur ??= self::slotMinutes();

        foreach (self::rankedSpecialists($specialty, $subject, $startsAt->toDateString()) as $l) {
            if (! self::isBusy((int) $l['id'], $startsAt, $dur)) {
                return User::find($l['id']);
            }
        }

        return null;
    }

    /** هل المحامي مشغول في نافذة [start, start+dur)؟ (تقاطع مع مواعيده المؤكدة). */
    /**
     * **المصدر الواحد** لفترات انشغال المحامي في يوم — بالدقائق منذ منتصف الليل.
     *
     * لماذا: كان الانشغال يُحسب في موضعين مختلفين بمصادر مختلفة، فيتناقضان:
     *   • isBusy/slotsFor هنا كانا يقرآن **Appointment وحده**.
     *   • Staff\MeetRequestController::busy كان يقرأ Meeting + Consult + MeetRequest.
     * فكان الموظف يجدول موعداً لمحامٍ في نفس لحظة اجتماعه أو دعوته — الفترة تظهر «متاحة»
     * في مودال الحجز وهي مشغولة فعلاً، لأن المودال يقرأ المصدر الأضيق.
     *
     * الأربعة مجتمعة هنا: Appointment + Meeting + Consult + MeetRequest.
     *
     * @return array<int, array{0:int,1:int,2:BusyKind}> فترات [بداية، نهاية، نوع الانشغال]
     */
    public static function busyIntervals(int $lawyerId, string $day): array
    {
        return self::busyIntervalsForMany([$lawyerId], $day)[$lawyerId] ?? [];
    }

    /**
     * الفترات المشغولة **لمجموعة محامين دفعةً واحدة** — أربعة استعلامات لا أربعة لكلّ محامٍ.
     *
     * وُجدت لأن `rankedSpecialists` كان يستعمل مصدراً ثانياً أضيق (`slotsFromAppointments`)
     * حفاظاً على ميزانيّة الاستعلامات، فافترق جوابُ «هل هذه الساعة متاحة؟» عن جواب
     * `slotsFor`: شبكة العميل تعرض ساعةً يحجبها المحرّك. فصار المصدر واحداً والميزانيّة محفوظة.
     *
     * **و`whereBetween` لا `whereDate`:** دالّةٌ على العمود تُبطل الفهرس
     * `['lawyer_id','starts_at']` فيُمسح الجدول كاملاً — ومع `lockForUpdate` في الحارس
     * يصير قفلاً يتّسع بنموّ البيانات.
     *
     * @param  array<int,int>  $lawyerIds
     * @return array<int, array<int, array{0:int,1:int,2:BusyKind}>> مفتاحه معرّف المحامي
     */
    public static function busyIntervalsForMany(array $lawyerIds, string $day): array
    {
        $ids = array_values(array_unique(array_map('intval', $lawyerIds)));

        if ($ids === []) {
            return [];
        }

        $toMin = function (?string $hm): ?int {
            if (! $hm || ! preg_match('/^(\d{1,2}):(\d{2})/', $hm, $m)) {
                return null;
            }

            return ((int) $m[1]) * 60 + (int) $m[2];
        };

        $dayStart = Carbon::parse($day)->startOfDay();
        $dayEnd = $dayStart->copy()->endOfDay();
        $out = array_fill_keys($ids, []);

        // صفٌّ بلا مسافةٍ محفوظة يشغل شريحةً واحدة بطولها النافذ — لا رقماً منقوشاً. والمحفوظ
        // (`duration_min` · `dur` التاريخيّ) مسافةٌ حُجزت يومها، لا عمرُ جلسةٍ تنتهي به.
        $slot = self::slotMinutes();
        // الفترة [بداية، نهاية، نوع الانشغال] — والنوع لخيار الحجز المتداخل (`BusyKind`)
        $add = function (int $lawyerId, ?int $from, int $dur, BusyKind $kind = BusyKind::Busy) use (&$out, $slot) {
            if ($from !== null && isset($out[$lawyerId])) {
                $out[$lawyerId][] = [$from, $from + ($dur ?: $slot), $kind];
            }
        };

        // (1) المواعيد (استبعاد الملغاة لتحرير تفرّغ المحامي فور الإلغاء)
        foreach (Appointment::whereIn('lawyer_id', $ids)
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, 'ملغى', 'ملغي'])
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$dayStart, $dayEnd])->get(['lawyer_id', 'starts_at', 'duration_min']) as $a) {
            $add((int) $a->lawyer_id, $toMin($a->starts_at?->format('H:i')), (int) $a->duration_min);
        }

        // (2) الاجتماعات — `dur` نصّيّ («60 دقيقة») فيُستخرج رقمه
        foreach (Meeting::whereIn('assigned_lawyer_id', $ids)
            ->whereNotIn('status', ['ملغى', 'ملغي', 'ملغاة'])
            ->whereBetween('starts_at', [$dayStart, $dayEnd])->get(['assigned_lawyer_id', 'starts_at', 'dur']) as $mt) {
            $d = (int) (preg_match('/\d+/', (string) $mt->dur, $mm) ? $mm[0] : $slot);
            $add((int) $mt->assigned_lawyer_id, $toMin($mt->starts_at?->format('H:i')), $d);
        }

        // (3) الاستشارات (استبعاد الملغاة لتحرير تفرّغ المحامي فور الإلغاء)
        foreach (Consult::whereIn('assigned_lawyer_id', $ids)
            ->whereNotIn('status', [ConsultStatus::Cancelled->value, 'ملغاة', 'ملغى', 'ملغي'])
            ->whereBetween('starts_at', [$dayStart, $dayEnd])->get(['assigned_lawyer_id', 'starts_at', 'duration_min']) as $c) {
            $add((int) $c->assigned_lawyer_id, $toMin($c->starts_at?->format('H:i')), (int) $c->duration_min);
        }

        // (4) دعوات الاجتماعات التي لم تُنفَّذ بعد.
        // '<' STAGE_EXECUTED يشمل المُرسَلة والمؤكَّدة، ويستثني المنتهية(4) والملغاة(5).
        // (`meet_requests` بلا `starts_at`، فالمطابقة على سلسلة `day` — تُوحَّد في الدفعة ٦.)
        foreach (MeetRequest::whereIn('assigned_lawyer_id', $ids)->where('day', $day)
            ->where('stage', '<', MeetRequest::STAGE_EXECUTED)->get(['assigned_lawyer_id', 'time', 'duration_min']) as $r) {
            $add((int) $r->assigned_lawyer_id, $toMin($r->time), (int) $r->duration_min);
        }

        // (5) جلسات المحاكم المجدولة لقضايا المحامي — كانت خارج المصادر فتُحجز استشارةٌ فوق جلسته.
        // «المجدولة» وحدها: المؤجّلة لم تنعقد في وقتها ولها جلستها التالية. والمدّة المُدخلة وإلا شريحة.
        foreach (CaseHearing::join('cases', 'cases.id', '=', 'case_hearings.case_id')
            ->whereIn('cases.assigned_lawyer_id', $ids)
            ->where('case_hearings.status', HearingStatus::Scheduled->value)
            ->whereBetween('case_hearings.starts_at', [$dayStart, $dayEnd])
            ->get(['cases.assigned_lawyer_id', 'case_hearings.starts_at', 'case_hearings.duration_min']) as $h) {
            $add((int) $h->assigned_lawyer_id, $toMin($h->starts_at?->format('H:i')), (int) $h->duration_min, BusyKind::Hearing);
        }

        return $out;
    }

    /**
     * شرائح اليوم من فتراتٍ محسوبة — المولّد **الوحيد**.
     *
     * كان في المشروع مولّدان: هذا (بحساب تداخلٍ صحيح) و`slotsFromAppointments`
     * (بمطابقة ساعة البداية على المواعيد وحدها) — والثاني هو ما يغذّي شبكة العميل.
     *
     * @param  array<int, array{0:int,1:int,2:BusyKind}>  $intervals
     * @return array<int, array{time:string,taken:bool,hard:bool}>
     */
    private static function slotsFromIntervals(array $intervals, Carbon $day): array
    {
        $slots = [];
        $pastMinute = self::pastMinuteFor($day);
        [$startHour, $endHour] = self::workHours();
        $length = self::slotMinutes();

        // **الخطوة طولُ الشريحة لا ساعة.** كانت الحلقة ساعيّة والطول ثابتاً ٦٠ فتطابقا؛ ولمّا صار
        // الطول إعداداً، شريحةُ ٣٠ دقيقة بخطوةٍ ساعيّة تترك نصف كلّ ساعةٍ بلا حجز. ولا شريحةَ
        // تبدأ ما لم تنتهِ قبل نهاية الساعات — وبالافتراض (٠–٢٤، ٦٠د) هي الأربع والعشرون نفسها.
        for ($from = $startHour * 60; $from + $length <= $endHour * 60; $from += $length) {
            $kind = self::kindWithin($intervals, $from, $from + $length);
            $past = $from <= $pastMinute;

            // `hard`: لا يُتجاوز ولو سمحت الإدارة بالحجز المتداخل — وقتٌ مضى أو جلسة محكمة
            $slots[] = [
                'time' => sprintf('%02d:%02d', intdiv($from, 60), $from % 60),
                'taken' => $kind !== BusyKind::Free || $past,
                'hard' => $kind === BusyKind::Hearing || $past,
            ];
        }

        return $slots;
    }

    /** هل تتقاطع الفترة [start, start+dur) مع أي انشغال؟ */
    public static function isBusy(int $lawyerId, Carbon $start, ?int $dur = null): bool
    {
        return self::conflictAt($lawyerId, $start, $dur) !== BusyKind::Free;
    }

    /**
     * **بماذا المحامي مشغول في هذا الوقت؟** — `Hearing` مقدَّمٌ على `Busy` لأنّه لا يُتجاوز.
     * ما يقرّره الحجز بها (رفضٌ أو قبولٌ بتنبيه) في `ConsultBooking::conflictVerdict`.
     */
    public static function conflictAt(int $lawyerId, Carbon $start, ?int $dur = null): BusyKind
    {
        $from = $start->hour * 60 + $start->minute;

        return self::kindWithin(self::busyIntervals($lawyerId, $start->toDateString()), $from, $from + ($dur ?? self::slotMinutes()));
    }

    /**
     * نوع الانشغال في النافذة [من، إلى) من فترات اليوم — المصدر الواحد لـ`conflictAt` وللشرائح.
     *
     * @param  array<int, array{0:int,1:int,2:BusyKind}>  $intervals
     */
    private static function kindWithin(array $intervals, int $from, int $to): BusyKind
    {
        $kind = BusyKind::Free;

        foreach ($intervals as [$s, $e, $k]) {
            if ($from < $e && $to > $s) {
                if ($k === BusyKind::Hearing) {
                    return BusyKind::Hearing;
                }
                $kind = BusyKind::Busy;
            }
        }

        return $kind;
    }

    /**
     * فترات اليوم لمحامٍ (ساعات الدوام وأيّامه وطول الشريحة من الإعدادات)، كلٌّ مع علامة المحجوز.
     *
     * اليوم المطلوب نفسه لا «أقرب يوم عمل» بعده: كان يُنقل صامتاً إلى الأحد فتعرض شبكة الجمعة
     * شرائح الأحد وتُحجز على الجمعة. ويومُ العطلة بلا شرائح.
     *
     * @return array<int, array{time:string,taken:bool,hard:bool}>
     */
    public static function slotsFor(int $lawyerId, ?string $date): array
    {
        $day = $date ? Carbon::parse($date)->startOfDay() : self::resolveDate(null);

        if (! self::isWorkDay($day)) {
            return [];
        }

        return self::slotsFromIntervals(self::busyIntervals($lawyerId, $day->toDateString()), $day);
    }

    /**
     * آخر دقيقة منقضية من اليوم المُعطى بتوقيت الخادم — الفترات الماضية تُعلَّم محجوزةً خادمياً
     * (كان الحجب بساعة متصفّح العميل وحدها، فمتصفّح بتوقيت مختلف يفتح فترات ماضية).
     *
     * بالدقيقة لا بالساعة لأنّ الشريحة قد تبدأ في منتصف ساعة؛ ومع الشرائح الساعيّة النتيجةُ
     * نفسها: الشريحة التي بدأت ساعتُها (١٤:٠٠ في ١٤:٣٠) منقضية، والتالية متاحة.
     */
    private static function pastMinuteFor(Carbon $day): int
    {
        if ($day->isToday()) {
            return now()->hour * 60 + now()->minute;
        }

        return $day->isPast() ? 24 * 60 : -1;
    }

    /** أقرب يوم عمل من تاريخ مُعطى (أو من اليوم إن لم يُعطَ). */
    public static function resolveDate(?string $date): Carbon
    {
        $day = $date ? Carbon::parse($date)->startOfDay() : Carbon::today();
        $guard = 0;
        while (! self::isWorkDay($day) && $guard++ < 7) {
            $day->addDay();
        }

        return $day;
    }

    /**
     * **مسافة الحجز** بالدقائق — طول الشريحة الذي لا يُحجز فيه للمحامي موعدان (`consult_slot_minutes`).
     *
     * ليست مدّة الجلسة: الاستشارة والاجتماع ينتهيان حين يُنهيان (قرار المالك 2026-09-26)، وما
     * يحتاج رقماً اسمياً لـZoom أو التقويم يأخذه من `SessionWindow::nominalMinutes()`.
     */
    public static function slotMinutes(): int
    {
        return SettingsRegistry::int('consult_slot_minutes');
    }

    /**
     * ساعات الحجز [البداية، النهاية) من الإعدادات.
     *
     * الحافظ يرفض نهايةً لا تتجاوز البداية (`SettingsRegistry::relationErrors`)، لكنّ القيمة قد
     * تفسد في القاعدة مباشرةً — ويومٌ بلا شريحةٍ واحدة يُغلق الحجز بصمت. فالفاسد يعود إلى
     * الافتراض المُعلَن بدل أن يوقف الميزة (نمط القيد عند القراءة في السجلّ نفسه).
     *
     * @return array{0:int,1:int}
     */
    public static function workHours(): array
    {
        $start = SettingsRegistry::int('consult_day_start');
        $end = SettingsRegistry::int('consult_day_end');

        return $end > $start ? [$start, $end] : [self::WORK_START, self::WORK_END];
    }

    /**
     * أيّام الدوام (`consult_work_days`) — والفاسد أو الفارغ يعود إلى الافتراض في السجلّ.
     *
     * @return list<int>
     */
    public static function workDays(): array
    {
        return SettingsRegistry::days('consult_work_days');
    }

    public static function isWorkDay(Carbon $day): bool
    {
        return in_array($day->dayOfWeek, self::workDays(), true);
    }

    /**
     * **حارس دوام المكتب على الخادم** — الشبكات لا تعرض إلّا شرائح الدوام، لكنّ النموذج قد يُرسل
     * وقتاً آخر (أو اقتراحاً قديماً سبق تغيير الساعات). يُرجع سبب الرفض أو `null`.
     */
    public static function officeHoursError(Carbon $start): ?string
    {
        [$from, $to] = self::workHours();
        $minute = $start->hour * 60 + $start->minute;

        if (! self::isWorkDay($start)) {
            return 'هذا اليوم خارج أيّام دوام المكتب — اختر يوماً من أيّام الدوام.';
        }

        if ($minute < $from * 60 || $minute + self::slotMinutes() > $to * 60) {
            return sprintf('الموعد خارج ساعات دوام المكتب (%02d:00–%02d:00).', $from, $to);
        }

        return null;
    }
}
