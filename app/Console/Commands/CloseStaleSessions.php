<?php

namespace App\Console\Commands;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Consult\AlertStaleSession;
use App\Domain\Journey\Transitions\Consult\EndSession;
use App\Domain\Journey\Transitions\Meeting\AlertStaleMeeting;
use App\Domain\Journey\Transitions\Meeting\EndMeeting;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Events\Journey\SessionEndedInSystem;
use App\Jobs\GenerateMeetingSummaryJob;
use App\Models\Consult;
use App\Models\JourneyTransition;
use App\Models\Meeting;
use App\Models\User;
use App\Support\ArabicCount;
use App\Support\Audit;
use App\Support\ConsultSessionOutcome;
use App\Support\Notify;
use App\Support\SessionWindow;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * **شبكة النسيان — تنبيهٌ للطاقم مرّةً واحدة، وإنهاءٌ في النظام وفي Zoom.**
 *
 * الاستشارة والاجتماع بلا مدّةٍ ثابتة — ينتهيان حين يُنهيان (قرار المالك 2026-09-26). لكنّ المضيف
 * قد ينسى «إنهاء»، وقد يضيع حدث `meeting.ended` من Zoom، فتبقى الجلسة «جارية»: الغرفة مفتوحة
 * وتسجيلها السحابيّ يستهلك الحصّة، والموعد «قيد الجلسة»، ولا ملخّص يُطلب.
 *
 * **تاريخ القرار:** كانت الشبكة تُنهي بنفسها، ثمّ قُرّر صباح 2026-09-26 أن تُنبّه فقط، ثمّ قرّر
 * المالك في اليوم نفسه (القرار الأخير النافذ) أن **تُنبّه وتُنهي** بعد `session_stale_minutes` من
 * البدء: جلسةٌ لم يُنهها أحد ستّ ساعات ليست منعقدةً في الواقع، وغرفتها المفتوحة ضررٌ قائم.
 *
 * فلكلّ جلسةٍ منسيّة، في مرورٍ واحد:
 * 1. **تنبيهٌ داخليّ مرّةً واحدة** للإدارة والمحامي المسنَد (والموظّف المتولّي محادثة التذكرة) —
 *    سطرُ `consult.stale_alerted` / `meeting.stale_alerted` ذاكرته، يرفض حارسُه الثاني.
 * 2. **الإنهاء بانتقال الإنهاء نفسه** (`EndSession` / `EndMeeting`) بمصدر `safety_net` وسبب
 *    `SessionWindow::STALE_END_REASON` — فيُغلق حدثُه غرفةَ Zoom (`SessionEndedInSystem` ⇒
 *    `EndZoomMeetingJob`) ويبثّ حال الغرفة، كزرّ الطاقم حرفاً.
 * 3. **ما بعد الإنهاء** كما بعد ويبهوك `meeting.ended`: للاستشارة `afterUnattendedEnd` (التذكرة
 *    «بانتظار ملخّص الجلسة» ووظيفة الختم)، وللاجتماع توليد المحضر.
 *
 * والتنبيه قبل الإنهاء وإن رُفض (نُبّه من قبل في نسخة «التنبيه وحده»): جلسةٌ نُبّه بها ولم تُنهَ
 * بعدُ تُنهى الآن ولا يتكرّر تنبيهها.
 */
class CloseStaleSessions extends Command
{
    protected $signature = 'sessions:close-stale';

    protected $description = 'إنهاء الجلسات — الاستشارات والاجتماعات — التي بدأت ولم يُنهها أحد بعد مهلة الإعدادات (في النظام وفي Zoom) مع تنبيه الطاقم مرّةً واحدة';

    public function handle(): int
    {
        $consults = 0;
        foreach (Consult::where('session', SessionState::Live->value)->get() as $consult) {
            $startedAt = $this->consultStartedAt($consult);
            if (! SessionWindow::isStale($startedAt)) {
                continue;
            }

            if ($this->alertOnce(new AlertStaleSession, $consult, $startedAt)) {
                $this->alertStaff([$consult->assigned_lawyer_id, $consult->ticket?->handler_id], "الاستشارة {$consult->ref}", $consult);
            }

            try {
                Workflow::run(new EndSession, $consult, null, $this->endPayload());
            } catch (TransitionDenied) {
                continue; // خُتمت بين القراءة والقفل (زرّ الطاقم أو Zoom) — لا شيء يُنهى
            }
            ConsultSessionOutcome::afterUnattendedEnd($consult);
            $consults++;
        }

        $meetings = 0;
        $started = Meeting::where(fn ($q) => $q->where('status', MeetingStatus::Live->value)
            ->orWhere(fn ($q) => $q->whereNotNull('join_time')
                ->whereNotIn('status', [MeetingStatus::Ended->value, MeetingStatus::Cancelled->value, MeetingStatus::Missed->value])))
            ->get();

        foreach ($started as $meeting) {
            $startedAt = $this->meetingStartedAt($meeting);
            if (! $meeting->isLive() || ! SessionWindow::isStale($startedAt)) {
                continue;
            }

            if ($this->alertOnce(new AlertStaleMeeting, $meeting, $startedAt)) {
                // لا متولّي بمعرّفٍ للاجتماع: `created_by` اسمٌ نصّيّ، ومطابقته بحسابٍ تخمين
                $this->alertStaff([$meeting->assigned_lawyer_id], "الاجتماع «{$meeting->title}» ({$meeting->ref})", $meeting);
            }

            try {
                Workflow::run(new EndMeeting, $meeting, null, $this->endPayload());
            } catch (TransitionDenied) {
                continue;
            }
            GenerateMeetingSummaryJob::dispatch($meeting, '');
            $meetings++;
        }

        $this->info("أُنهيت {$consults} استشارة و{$meetings} اجتماع بقيت جاريةً بعد مهلة النسيان.");

        return self::SUCCESS;
    }

    /**
     * التنبيه مرّةً واحدة — `true` إن سُجّل الآن (فيُرسَل)، و`false` إن نُبّه من قبل.
     *
     * @param  AlertStaleSession|AlertStaleMeeting  $alert
     */
    private function alertOnce(object $alert, Consult|Meeting $entity, ?CarbonInterface $startedAt): bool
    {
        try {
            Workflow::run($alert, $entity, null, [
                'reason' => SessionWindow::STALE_ALERT_REASON,
                'started_at' => $startedAt?->toIso8601String(),
                'after_minutes' => SessionWindow::staleAfterMinutes(),
            ]);

            return true;
        } catch (TransitionDenied) {
            return false;
        }
    }

    /** @return array<string, mixed> حمولة الإنهاء الآليّ — مصدره وسببه يُحفظان في سطر الرحلة */
    private function endPayload(): array
    {
        return [
            'source' => SessionEndedInSystem::VIA_SAFETY_NET,
            'reason' => SessionWindow::STALE_END_REASON,
        ];
    }

    /**
     * لحظة بدء الاستشارة المعروفة: أوّل دخولٍ سجّله Zoom، ثمّ موعدها، ثمّ سطرُ بدئها في سجلّ
     * الرحلة (استشارةٌ بلا موعد بدأها الطاقم يدوياً) — وبلا شيءٍ منها لا حكم.
     */
    private function consultStartedAt(Consult $consult): ?CarbonInterface
    {
        if ($consult->join_time !== null) {
            return $consult->join_time;
        }
        if ($consult->starts_at !== null) {
            return $consult->starts_at;
        }

        $at = JourneyTransition::where('entity_type', class_basename($consult))
            ->where('entity_id', $consult->getKey())
            ->where('to_state', SessionState::Live->value)
            // الدخول إلى «جارية» لا البقاء فيها — سطرُ التنبيه نفسه ينتقل من «جارية» إليها
            ->where('from_state', '<>', SessionState::Live->value)
            ->latest('id')
            ->value('created_at');

        return $at !== null ? Carbon::parse($at) : null;
    }

    /** لحظة بدء الاجتماع المعروفة: أوّل دخولٍ سجّله Zoom، وإلّا موعده. */
    private function meetingStartedAt(Meeting $meeting): ?CarbonInterface
    {
        return $meeting->join_time ?? $meeting->startsAtResolved();
    }

    /**
     * **تنبيهٌ داخليّ للطاقم وحده** — الإدارة ومن سُمّي من المسنَدين؛ الموكّل لا يُنبَّه بهذا (يصله
     * ما يصله من الإنهاء نفسه). ويبقى أثرٌ في سجلّ التدقيق.
     *
     * @param  list<int|null>  $assigned
     */
    private function alertStaff(array $assigned, string $label, Consult|Meeting $entity): void
    {
        // المهلة بدقائقها في الإعداد، وبوحدتها الطبيعيّة في النصّ — «6 ساعات» لا «360 دقيقة»
        $body = "{$label}: ".SessionWindow::STALE_ALERT_REASON.' — مضت '.ArabicCount::duration(SessionWindow::staleAfterMinutes())
            .' على بدئها، فأُنهيت آليّاً في النظام وأُغلقت غرفتها في Zoom. اكتب ملخّصها من صفحتها.';

        $recipients = User::where('role', Role::Admin)->pluck('id')->all();
        foreach ($assigned as $userId) {
            if ($userId !== null) {
                $recipients[] = $userId;
            }
        }
        foreach (array_unique(array_map('intval', $recipients)) as $userId) {
            Notify::send($userId, 'video', 't-amber', $body);
        }

        Audit::log(
            action: 'إنهاءٌ آليّ لجلسةٍ لم تُنهَ',
            description: $body,
            category: $entity instanceof Consult ? 'استشارات' : 'اجتماعات',
            severity: 'warning',
            auditable: $entity,
        );
    }
}
