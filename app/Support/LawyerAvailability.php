<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Services\LegalAiService;
use Illuminate\Support\Carbon;

/**
 * محرّك التفرّغ والاقتراح لحجز الاستشارة:
 * - يرشّح المحامين بنفس التخصّص ويرتّبهم بأولوية الذكاء الاصطناعي (مستنداً لسجلّ النجاح والحمل).
 * - يحسب سجلّ النجاح لكل محامٍ (نسبة/عدد المغلق من التذاكر والقضايا والتنفيذ).
 * - يولّد فترات المواعيد المتاحة ويكشف الانشغال لمنع الحجز المزدوج.
 * كل المنطق حتميّ ويعمل بلا ذكاء اصطناعي (اختبارات/غياب مزوّد).
 */
class LawyerAvailability
{
    /** أيام العمل: الأحد(0)…الخميس(4) بتقويم Carbon. */
    private const WORK_DAYS = [0, 1, 2, 3, 4];

    private const WORK_START = 9;   // 09:00

    private const WORK_END = 17;    // 17:00 (آخر بداية 16:00 لموعد 60د)

    private const SLOT_MIN = 60;

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

        // وسم كل مرشّح بسجلّ النجاح والحمل، وترتيب حتميّ ابتدائي (الأكثر إنجازاً ← الأقل حملاً)
        $rows = $pool->map(fn ($u) => [
            'id' => (int) $u->id,
            'name' => $u->name,
            'dept' => $u->department ?: '—',
            'success' => self::successScore($u),
            'load' => self::openLoad((int) $u->id),
        ])->sort(fn ($a, $b) => [$b['success']['closed'], $b['success']['rate'], $a['load'], $a['id']]
            <=> [$a['success']['closed'], $a['success']['rate'], $b['load'], $b['id']]
        )->values();

        // ترتيب ذكي عبر الذكاء الاصطناعي (يعيد الترتيب الحتميّ عند التعذّر)
        $orderedIds = app(LegalAiService::class)->rankLawyers($specialty, $subject ?: $specialty, $rows->all());
        $byId = $rows->keyBy('id');
        $ranked = collect($orderedIds)->map(fn ($id) => $byId->get($id))->filter()->values();

        // إلحاق فترات اليوم المطلوب لكل محامٍ
        return $ranked->map(function (array $row) use ($date) {
            $slots = self::slotsFor($row['id'], $date);
            $row['slots'] = $slots;
            $row['freeCount'] = count(array_filter($slots, fn ($s) => ! $s['taken']));

            return $row;
        })->all();
    }

    /**
     * سجلّ النجاح: نسبة/عدد المغلق من تذاكر وقضايا وتنفيذات المحامي.
     *
     * @return array{rate:int,closed:int,total:int}
     */
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
     * فترات يوم عمل لمحامٍ (09:00…16:00 بطول 60د)، كلٌّ مع علامة المحجوز.
     * يوم عطلة (جمعة/سبت) → لا فترات.
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
        for ($h = self::WORK_START; $h < self::WORK_END; $h++) {
            $time = sprintf('%02d:00', $h);
            $slots[] = ['time' => $time, 'taken' => in_array($time, $taken, true)];
        }

        return $slots;
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
