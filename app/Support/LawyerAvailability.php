<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * محرّك التفرّغ والاقتراح لحجز الاستشارة:
 * - يرشّح المحامين بنفس التخصّص ويرتّبهم حتميّاً بسجلّ النجاح والحمل (فوري، بلا نداء AI متزامن).
 * - يحسب سجلّ النجاح لكل محامٍ (نسبة/عدد المغلق من التذاكر والقضايا والتنفيذ).
 * - يولّد فترات المواعيد المتاحة ويكشف الانشغال لمنع الحجز المزدوج.
 * كل المنطق حتميّ ويعمل بلا ذكاء اصطناعي (اختبارات/غياب مزوّد).
 */
class LawyerAvailability
{
    /** الاستشارات متاحة طوال الأسبوع: الأحد(0)…السبت(6) بتقويم Carbon. */
    private const WORK_DAYS = [0, 1, 2, 3, 4, 5, 6];

    private const WORK_START = 0;   // 00:00 — متاح طوال 24 ساعة

    private const WORK_END = 24;    // آخر بداية 23:00 لموعد 60د

    private const SLOT_MIN = 60;    // كل استشارة ساعة واحدة

    /** حالات «مغلق/منجز» لكل نوع — تُغذّي سجلّ النجاح. */
    private const CLOSED_TICKETS = ['مكتملة', 'مغلقة'];

    private const CLOSED_CASES = ['صدر الحكم', 'مغلقة', 'مؤرشفة'];

    private const CLOSED_EXECS = ['مكتمل', 'مغلق'];

    /**
     * المحامون المتخصّصون مرتّبون بأولوية الذكاء الاصطناعي، مع سجلّ النجاح والتفرّغ ليوم مُعطى.
     *
     * @return array<int, array{id:int,name:string,dept:string,success:array,load:int,slots:array,freeCount:int}>
     */
    public static function rankedSpecialists(string $specialty, ?string $subject, ?string $date = null): array
    {
        $lawyers = User::where('role', Role::Lawyer)->where('status', 'active')->get();
        if ($lawyers->isEmpty()) {
            return [];
        }

        // مطابقة التخصّص إن وُجد مطابقون، وإلا فكل المحامين
        $matched = $lawyers->filter(fn ($u) => Specialties::matches($u->department, $specialty))->values();
        $pool = $matched->isNotEmpty() ? $matched : $lawyers;
        $ids = $pool->pluck('id')->map(fn ($i) => (int) $i)->all();

        // تجميع دفعة واحدة (بدل ~8 استعلامات لكل محامٍ): حالات التذاكر/القضايا/التنفيذ لكل الپول
        $tickets = Ticket::whereIn('assigned_lawyer_id', $ids)->get(['assigned_lawyer_id', 'status'])->groupBy('assigned_lawyer_id');
        $cases = LegalCase::whereIn('assigned_lawyer_id', $ids)->get(['assigned_lawyer_id', 'status'])->groupBy('assigned_lawyer_id');
        $execs = Execution::whereIn('assigned_lawyer_id', $ids)->get(['assigned_lawyer_id', 'status'])->groupBy('assigned_lawyer_id');

        // مواعيد اليوم المطلوب لكل الپول دفعة واحدة (بدل استعلام لكل محامٍ)
        $day = self::resolveDate($date);
        $isWorkDay = in_array($day->dayOfWeek, self::WORK_DAYS, true);
        $apptsByLawyer = $isWorkDay
            ? Appointment::whereIn('lawyer_id', $ids)->whereNotNull('starts_at')->whereDate('starts_at', $day->toDateString())
                ->get(['lawyer_id', 'starts_at'])->groupBy('lawyer_id')
            : collect();

        // وسم كل مرشّح بسجلّ النجاح والحمل من البيانات المجمّعة (بلا استعلام لكل محامٍ)
        $rows = $pool->map(function ($u) use ($tickets, $cases, $execs) {
            $id = (int) $u->id;
            $t = $tickets->get($id, collect());
            $c = $cases->get($id, collect());
            $e = $execs->get($id, collect());
            $total = $t->count() + $c->count() + $e->count();
            $closed = $t->whereIn('status', self::CLOSED_TICKETS)->count()
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
                'load' => $t->whereNotIn('status', self::CLOSED_TICKETS)->count(),
            ];
        })->sort(fn ($a, $b) => [$b['success']['closed'], $b['success']['rate'], $a['load'], $a['id']]
            <=> [$a['success']['closed'], $a['success']['rate'], $b['load'], $b['id']]
        )->values();

        // ترتيب حتميّ فوري (الأكثر إنجازاً ← الأقل حملاً) — بلا نداء AI متزامن على نقطة تفاعلية
        // (كان rankLawyers يعلّق طلب اختيار الموعد حتى 150ث؛ الترتيب الحتميّ سريع وسليم).
        return $rows->map(function (array $row) use ($apptsByLawyer, $isWorkDay, $day) {
            $slots = self::slotsFromAppointments($apptsByLawyer->get($row['id'], collect()), $isWorkDay, $day);
            $row['slots'] = $slots;
            $row['freeCount'] = count(array_filter($slots, fn ($s) => ! $s['taken']));

            return $row;
        })->all();
    }

    /**
     * الإسناد التلقائيّ (العميل لا يختار): أعلى مختصّ في نوع المشكلة (تخصّص + سجلّ إنجاز)
     * غير مشغول في الوقت المطلوب. يعيد المحامي، أو null إن لم يتوفّر أيّ مختصّ في تلك الفترة.
     */
    public static function assignLawyer(string $specialty, ?string $subject, Carbon $startsAt, int $dur = self::SLOT_MIN): ?User
    {
        foreach (self::rankedSpecialists($specialty, $subject, $startsAt->toDateString()) as $l) {
            if (! self::isBusy((int) $l['id'], $startsAt, $dur)) {
                return User::find($l['id']);
            }
        }

        return null;
    }

    /**
     * سجلّ النجاح: نسبة/عدد المغلق من تذاكر وقضايا وتنفيذات المحامي.
     *
     * @return array{rate:int,closed:int,total:int}
     */
    /** ⚠️ غير مستعملة حالياً: بقيت من مسار الترتيب بالذكاء الاصطناعي المُستبدَل. */
    public static function successScore(User $lawyer): array
    {
        $id = (int) $lawyer->id;

        $tTotal = Ticket::where('assigned_lawyer_id', $id)->count();
        $tClosed = Ticket::where('assigned_lawyer_id', $id)->whereIn('status', self::CLOSED_TICKETS)->count();

        $cTotal = LegalCase::where('assigned_lawyer_id', $id)->count();
        $cClosed = LegalCase::where('assigned_lawyer_id', $id)->whereIn('status', self::CLOSED_CASES)->count();

        $eTotal = Execution::where('assigned_lawyer_id', $id)->count();
        $eClosed = Execution::where('assigned_lawyer_id', $id)->whereIn('status', self::CLOSED_EXECS)->count();

        $total = $tTotal + $cTotal + $eTotal;
        $closed = $tClosed + $cClosed + $eClosed;

        return [
            'rate' => $total > 0 ? (int) round($closed / $total * 100) : 0,
            'closed' => $closed,
            'total' => $total,
        ];
    }

    /** الحمل المفتوح (تذاكر غير مغلقة) لموازنة الترتيب عند تعادل النجاح. */
    /** ⚠️ غير مستعملة حالياً: بقيت من مسار الترتيب بالذكاء الاصطناعي المُستبدَل. */
    public static function openLoad(int $lawyerId): int
    {
        return Ticket::where('assigned_lawyer_id', $lawyerId)
            ->whereNotIn('status', self::CLOSED_TICKETS)
            ->count();
    }

    /** هل المحامي مشغول في نافذة [start, start+dur)؟ (تقاطع مع مواعيده المؤكدة). */
    public static function isBusy(int $lawyerId, Carbon $start, int $dur = self::SLOT_MIN): bool
    {
        $end = $start->copy()->addMinutes($dur);

        return Appointment::where('lawyer_id', $lawyerId)
            ->whereNotNull('starts_at')
            ->whereDate('starts_at', $start->toDateString())
            ->get()
            ->contains(function (Appointment $a) use ($start, $end) {
                $aStart = $a->starts_at;
                $aEnd = $aStart->copy()->addMinutes((int) ($a->duration_min ?: self::SLOT_MIN));

                return $start->lt($aEnd) && $end->gt($aStart);
            });
    }

    /**
     * فترات اليوم لمحامٍ (00:00…23:00 بطول 60د، متاحة طوال الأسبوع)، كلٌّ مع علامة المحجوز.
     *
     * @return array<int, array{time:string,taken:bool}>
     */
    public static function slotsFor(int $lawyerId, ?string $date): array
    {
        $day = self::resolveDate($date);
        if (! in_array($day->dayOfWeek, self::WORK_DAYS, true)) {
            return [];
        }

        // مواعيد المحامي المحجوزة ذلك اليوم (بداياتها بصيغة H:i)
        $taken = Appointment::where('lawyer_id', $lawyerId)
            ->whereNotNull('starts_at')
            ->whereDate('starts_at', $day->toDateString())
            ->get()
            ->map(fn (Appointment $a) => $a->starts_at->format('H:i'))
            ->all();

        $slots = [];
        $pastHour = self::pastHourFor($day);
        for ($h = self::WORK_START; $h < self::WORK_END; $h++) {
            $time = sprintf('%02d:00', $h);
            $slots[] = ['time' => $time, 'taken' => in_array($time, $taken, true) || $h <= $pastHour];
        }

        return $slots;
    }

    /**
     * يبني فترات اليوم من مواعيد المحامي المُحمّلة مسبقاً (بلا استعلام) — يخدم المسار المجمّع.
     *
     * @param  Collection<int, Appointment>  $appts
     * @return array<int, array{time:string,taken:bool}>
     */
    private static function slotsFromAppointments($appts, bool $isWorkDay, Carbon $day): array
    {
        if (! $isWorkDay) {
            return [];
        }
        $taken = $appts->map(fn (Appointment $a) => $a->starts_at->format('H:i'))->all();
        $slots = [];
        $pastHour = self::pastHourFor($day);
        for ($h = self::WORK_START; $h < self::WORK_END; $h++) {
            $time = sprintf('%02d:00', $h);
            $slots[] = ['time' => $time, 'taken' => in_array($time, $taken, true) || $h <= $pastHour];
        }

        return $slots;
    }

    /**
     * آخر ساعة منقضية لليوم المُعطى بتوقيت الخادم — الفترات الماضية تُعلَّم محجوزةً خادمياً
     * (كان الحجب بساعة متصفّح العميل وحدها، فمتصفّح بتوقيت مختلف يفتح فترات ماضية).
     */
    private static function pastHourFor(Carbon $day): int
    {
        if ($day->isToday()) {
            return now()->hour;
        }

        return $day->isPast() ? 24 : -1;
    }

    /** أقرب يوم عمل من تاريخ مُعطى (أو من اليوم إن لم يُعطَ). */
    public static function resolveDate(?string $date): Carbon
    {
        $day = $date ? Carbon::parse($date)->startOfDay() : Carbon::today();
        $guard = 0;
        while (! in_array($day->dayOfWeek, self::WORK_DAYS, true) && $guard++ < 7) {
            $day->addDay();
        }

        return $day;
    }

    public static function slotMinutes(): int
    {
        return self::SLOT_MIN;
    }
}
