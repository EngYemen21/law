<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\AppointmentStatus;
use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Events\Journey\ConsultRescheduled;
use App\Models\Consult;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **إعادة جدولة استشارة لم تنعقد** — تعود إلى «بانتظار تحديد الموعد».
 *
 * كان الحارس لا يمنع الجلسة الجارية: ضغطةٌ أثناء الجلسة تحذف اجتماع Zoom وتُرجع الحالة
 * (ع١٥). وحذفُ اجتماع Zoom كان يقع قبل الحفظ داخل الطلب؛ صار حدثاً بعد الالتزام
 * بمعرّفاتٍ محفوظة قبل التصفير.
 *
 * @extends Transition<Consult>
 */
final class RescheduleConsult extends Transition
{
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

    public function guard(Model $entity, array $payload): ?string
    {
        $status = ConsultStatus::tryFrom((string) $entity->status);

        return match (true) {
            $status?->isClosed() === true => 'الاستشارة انتهت أو أُلغيت — أنشئ طلباً جديداً بدل إعادة جدولتها.',
            $entity->session === SessionState::Ended->value => 'الجلسة انتهت — لا يمكن إعادة جدولتها.',
            $entity->session === SessionState::Live->value => 'الجلسة منعقدة الآن — أنهِها قبل إعادة جدولتها.',
            $status?->isPreSession() === true => 'الاستشارة لم تُجدول بعد أصلاً.',
            default => null,
        };
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $this->oldMeetId = filled($entity->meet_id) ? (string) $entity->meet_id : null;
        $this->oldWhen = $entity->when_label ?: '—';

        $entity->appointment?->update(['status' => AppointmentStatus::Cancelled->value, 'tone' => 'b-grey', 'when_kind' => 'past']);

        /*
         * **لا يبقى تاريخٌ قديم يُعاد بناؤه.** التقاويم الثلاثة وملفّ الاشتراك (ICS)
         * تبني الموعد من `day`/`time` حين يغيب `starts_at` — فكان مسحُ `starts_at` وحده يُبقي
         * الاستشارة على موعدها الملغى في كلّ تقويم (قيسَ على CN-2026-0336).
         * ويبقى `appointment_id`: لوحة المواعيد وسجلّ العميل يقرآن تفاصيل الموعد الملغى من خلاله.
         */
        $entity->forceFill([
            'session' => SessionState::Waiting->value,
            'starts_at' => null,
            'day' => null,
            'time' => null,
            'when_label' => 'بانتظار اختيار موعد جديد',
            'meet_id' => null,
            'meet_link' => null,
            'host_link' => null,
            'meet_password' => null,
            'link_released_at' => null,
            'reminder_24h_sent_at' => null,
            'reminder_30m_sent_at' => null,
        ]);
        $entity->logAudit($actor->name ?? 'النظام', 'إعادة الجدولة', $this->oldWhen, 'بانتظار اختيار موعد جديد');

        $ticket = $entity->ticket;
        if ($ticket !== null && $ticket->status === TicketStatus::Scheduled->value) {
            $ticket->update([
                // مدفوعةٌ أصلاً — الخطوة التالية حجز الطاقم لموعدٍ جديد، لا طلب استشارة
                'status' => TicketStatus::AwaitingSchedule->value,
                'tone' => TicketJourney::toneFor(TicketStatus::AwaitingSchedule->value),
                'last_message' => 'أُعيدت جدولة الجلسة — يُحدَّد موعد جديد',
                'date_label' => 'الآن',
            ]);
            $this->ticketReverted = true;
        }
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        return [new ConsultRescheduled(
            $entity, $this->oldWhen, $this->oldMeetId,
            $actor->name ?? 'النظام', $this->ticketReverted,
        )];
    }
}
