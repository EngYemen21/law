<?php

namespace App\Support;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * **حِمل المحامي — المصدر الواحد لشاشات الإدارة** (تطوير صفحة «المحامون» 2026-09-29).
 *
 * كانت «المحامون» تعدّ التذاكر وحدها، و«التوزيع» تعدّ الأنواع الأربعة باستعلامات لكلّ محامٍ وبقائمة
 * حالاتٍ عربيّة مكتوبة في المتحكّم تُسقط الاستشارة التي «لم يحضر» عميلها وهي مفتوحةٌ رسميّاً — فيظهر
 * المحامي الواحد بحِملين. هنا الحِمل بتعريفات «المفتوح» الرسميّة لكلّ نوع، دفعةً واحدة لكلّ المحامين.
 *
 * ليس هو موازنة التوزيع الآليّ (`TicketAssignment::pool` يوازن التذاكر المفتوحة وحدها) ولا ترتيب حجز
 * الاستشارة (`LawyerAvailability`) — لكلٍّ منهما غرضه.
 */
final class LawyerWorkload
{
    /** وزن كلّ نوعٍ في الحِمل — القضيّة والتنفيذ أثقل من التذكرة والاستشارة. */
    public const WEIGHTS = ['tickets' => 1, 'cases' => 2, 'executions' => 2, 'consults' => 1];

    /** **الافتراض المُعلَن** لـ`workload_moderate_from` — أقلّ منه «متاح». */
    public const MODERATE_FROM = 5;

    /** **الافتراض المُعلَن** لـ`workload_busy_from` — منه فما فوق «مشغول»، وما بينهما «متوسّط». */
    public const BUSY_FROM = 15;

    /**
     * @param  array<int, int>  $lawyerIds  تُطبَّع (تكرارٌ أو مفاتيح متفرّقة لا تضرّ)
     * @return array<int, array{tickets:int, cases:int, executions:int, consults:int, total:int, capacity:string, overdueTasks:int, upcomingMeetings:int}>
     */
    public static function forMany(array $lawyerIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $lawyerIds)));
        if ($ids === []) {
            return [];
        }

        $tickets = self::countBy(Ticket::query()->open(), 'assigned_lawyer_id', $ids);
        $cases = self::countBy(LegalCase::query()->active(), 'assigned_lawyer_id', $ids);
        $executions = self::countBy(Execution::whereNotIn('status', Execution::CLOSED_STATUSES), 'assigned_lawyer_id', $ids);
        $consults = self::countBy(Consult::whereNotIn('status', Consult::CLOSED_STATUSES), 'assigned_lawyer_id', $ids);
        // المتأخّرة بتعريف `Task::isOverdue`: غير منجزة، واستحقاقها قبل اليوم
        $overdue = self::countBy(Task::where('status', '!=', Task::DONE)->whereNotNull('due_at')->where('due_at', '<', today()), 'assigned_to', $ids);
        $meetings = self::upcomingMeetings($ids);

        $out = [];
        foreach ($ids as $id) {
            $row = [
                'tickets' => $tickets[$id] ?? 0,
                'cases' => $cases[$id] ?? 0,
                'executions' => $executions[$id] ?? 0,
                'consults' => $consults[$id] ?? 0,
            ];
            $total = 0;
            foreach (self::WEIGHTS as $kind => $weight) {
                $total += $row[$kind] * $weight;
            }

            $out[$id] = $row + [
                'total' => $total,
                'capacity' => self::capacity($total),
                'overdueTasks' => $overdue[$id] ?? 0,
                'upcomingMeetings' => $meetings[$id] ?? 0,
            ];
        }

        return $out;
    }

    /** مفتاحٌ لاتينيّ ثابت للواجهة: available · moderate · busy. */
    public static function capacity(int $total): string
    {
        return match (true) {
            $total < SettingsRegistry::int('workload_moderate_from') => 'available',
            $total < SettingsRegistry::int('workload_busy_from') => 'moderate',
            default => 'busy',
        };
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private static function countBy(Builder $query, string $column, array $ids): array
    {
        return $query->whereIn($column, $ids)
            ->select("{$column} as owner")
            ->selectRaw('count(*) as c')
            ->groupBy($column)
            ->pluck('c', 'owner')
            ->mapWithKeys(fn ($c, $owner) => [(int) $owner => (int) $c])
            ->all();
    }

    /**
     * الاجتماعات القادمة بتعريف `Meeting::isUpcoming` نفسه (يشمل الجاري، ويستثني ما فات بلا انعقاد) —
     * النهائيّة تُستبعد في الاستعلام، والباقي يحكم فيه النموذج.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private static function upcomingMeetings(array $ids): array
    {
        return Meeting::whereIn('assigned_lawyer_id', $ids)
            ->whereNotIn('status', [MeetingStatus::Ended->value, MeetingStatus::Cancelled->value, MeetingStatus::Missed->value])
            ->get()
            ->filter(fn (Meeting $m) => $m->isUpcoming())
            ->countBy(fn (Meeting $m) => (int) $m->assigned_lawyer_id)
            ->all();
    }
}
