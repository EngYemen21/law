<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\AppointmentStatus;
use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\RescheduleReason;
use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Domain\Journey\Transitions\Ticket\TicketAwaitsSchedule;
use App\Domain\Journey\Workflow;
use App\Events\Journey\ConsultRescheduled;
use App\Models\Consult;
use App\Models\User;
use App\Support\Booking\BookingMoved;
use Illuminate\Database\Eloquent\Model;

/**
 * **إعادة جدولة استشارة لم تنعقد** — يُلغى موعدها وتعود إلى «بانتظار تحديد الموعد»، والمكتب
 * يحجز الموعد التالي (العميل لا يختار موعده — قرار المالك 2026-09-14).
 *
 * **دورةٌ لها ذاكرة** (قرار المالك 2026-09-25):
 *
 * - **سببٌ إلزاميّ من قائمةٍ مغلقة** (`RescheduleReason`) يُحفظ في سجلّ الرحلة وعلى الموعد الملغى.
 * - **الموعد الملغى يبقى مرتبطاً بالاستشارة** (`appointments.consult_id`) بسببه ووقت إلغائه، فلا
 *   تمحو الإعادةُ التاليةُ أثرَه.
 * - **سقفٌ:** مرّتان، والثالثة للإدارة العليا وحدها (`LIMIT`).
 * - **حالة الفرز تُحفظ** في `resume_status` فتعود إليها الاستشارة حين يُعتمد موعدها الجديد
 *   (`PublishAppointment`) — كانت «محالة للمحامي» تعود «جديدة» فيُعاد فرزها.
 * - **طلبُ العميل المعلّق يُقضى** بالإعادة نفسها.
 * - **والتذكرة تتحرّك بانتقالها هي** (`TicketAwaitsSchedule`) لا بكتابةٍ جانبيّة بلا سجلّ.
 *
 * وحذفُ اجتماع Zoom بعد الالتزام وفي الخلفيّة (`HandleConsultRescheduled`) بمعرّفٍ حُفظ قبل التصفير.
 *
 * الحمولة: `reason_code` (قيمة `RescheduleReason`) · `note` · `reason` (السطر المقروء — يسجّله المحرّك).
 *
 * @extends Transition<Consult>
 */
final class RescheduleConsult extends Transition
{
    /** سقفُ إعادة الجدولة لغير الإدارة العليا. */
    public const LIMIT = 2;

    /**
     * حالاتُ الفرز التي تستحقّ أن تُستعاد بعد الموعد الجديد. «لم يحضر» ليست منها: الموعد
     * الجديد جلسةٌ جديدة، فتبدأ «جديدة».
     */
    private const RESUMABLE = [
        ConsultStatus::New,
        ConsultStatus::AwaitingData,
        ConsultStatus::AwaitingEmployeeApproval,
        ConsultStatus::ReadyForLawyer,
        ConsultStatus::ReferredToLawyer,
    ];

    private ?string $oldMeetId = null;

    private string $oldWhen = '—';

    private bool $ticketReverted = false;

    public function name(): string
    {
        return 'consult.reschedule';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return ConsultStatus::AwaitingSchedule->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor !== null && ! $actor->isAdmin() && (int) $entity->reschedule_count >= self::LIMIT) {
            return 'أُعيدت جدولة هذه الاستشارة '.self::LIMIT.' مرّات — إعادتها مرّةً أخرى للإدارة العليا وحدها.';
        }

        return null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        $status = ConsultStatus::tryFrom((string) $entity->status);

        $state = match (true) {
            $status?->isClosed() === true => 'الاستشارة انتهت أو أُلغيت — أنشئ طلباً جديداً بدل إعادة جدولتها.',
            $entity->session === SessionState::Ended->value => 'الجلسة انتهت — لا يمكن إعادة جدولتها.',
            $entity->session === SessionState::Live->value => 'الجلسة منعقدة الآن — أنهِها قبل إعادة جدولتها.',
            $status?->isPreSession() === true => 'الاستشارة لم تُجدول بعد أصلاً.',
            default => null,
        };

        if ($state !== null || ! array_key_exists('reason_code', $payload)) {
            return $state; // `allowed()` يسأل بلا حمولة — فالسبب يُفحص حين يُرسل
        }

        $reason = RescheduleReason::tryFrom((string) $payload['reason_code']);

        return match (true) {
            $reason === null => 'اختر سبب إعادة الجدولة من القائمة.',
            $reason->needsNote() && trim((string) ($payload['note'] ?? '')) === '' => 'اكتب شرحاً مختصراً للسبب.',
            default => null,
        };
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $reasonLine = (string) ($payload['reason'] ?? 'غير محدَّد');
        $this->oldMeetId = filled($entity->meet_id) ? (string) $entity->meet_id : null;
        $this->oldWhen = $entity->when_label ?: '—';

        // الموعد الملغى يبقى في سجلّ الاستشارة بسببه — لا يُحذف ولا يُنسى
        $entity->appointment?->update([
            'status' => AppointmentStatus::Cancelled->value,
            'tone' => 'b-grey',
            'when_kind' => 'past',
            'consult_id' => $entity->getKey(),
            'cancelled_at' => now(),
            'cancel_reason' => $reasonLine,
        ]);

        $status = ConsultStatus::tryFrom((string) $entity->status);

        /*
         * **لا يبقى تاريخٌ قديم يُعاد بناؤه.** التقاويم الثلاثة وملفّ الاشتراك تبني الموعد من
         * `day`/`time` حين يغيب `starts_at` (قيسَ على CN-2026-0336). و`appointment_id` يبقى
         * مشيراً للملغى حتى يُحجز التالي — لوحة المواعيد تقرأ تفاصيله من خلاله.
         *
         * وأختام التذكير وإطلاق الرابط من مصدرها الواحد `BookingMoved::markers` — كانت تُكتب هنا
         * نسخةً ثانية.
         */
        $entity->forceFill(BookingMoved::markers($entity) + [
            'session' => SessionState::Waiting->value,
            'starts_at' => null,
            'day' => null,
            'time' => null,
            'when_label' => 'بانتظار تحديد موعد جديد',
            'meet_id' => null,
            'meet_link' => null,
            'host_link' => null,
            'meet_password' => null,
            'reschedule_count' => (int) $entity->reschedule_count + 1,
            'resume_status' => in_array($status, self::RESUMABLE, true) ? $status->value : null,
            'reschedule_requested_at' => null,
            'reschedule_request_note' => null,
        ]);
        $entity->logAudit($actor->name ?? 'النظام', 'إعادة الجدولة — '.$reasonLine, $this->oldWhen, 'بانتظار تحديد موعد جديد');

        $ticket = $entity->ticket;
        if ($ticket !== null && $ticket->status === TicketStatus::Scheduled->value) {
            Workflow::run(new TicketAwaitsSchedule, $ticket, $actor, [
                'message' => 'أُعيدت جدولة الجلسة ('.$reasonLine.') — يُحدَّد موعدٌ جديد',
                'consult_ref' => $entity->ref,
            ]);
            $this->ticketReverted = true;
        }
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        return [new ConsultRescheduled(
            $entity, $this->oldWhen, $this->oldMeetId,
            $actor, $this->ticketReverted, (string) ($payload['reason'] ?? 'غير محدَّد'),
        )];
    }

    public function record(array $payload): array
    {
        return ['reason_code' => $payload['reason_code'] ?? null, 'note' => $payload['note'] ?? null];
    }
}
