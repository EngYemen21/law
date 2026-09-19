<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\AppointmentStatus;
use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Events\Journey\AppointmentPublished;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\LegalAiService;
use App\Support\AppointmentCard;
use App\Support\ConsultBooking;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **الإدارة تعتمد الموعد وتنشره** — بعد اقتراح موظّف (مع تعديلٍ أو بدونه)، أو حجزاً مباشراً منها.
 *
 * قرار المالك 2026-09-14: الإدارة لا ترفض اقتراح الموظّف بل «تعدّله وتعتمده مباشرةً».
 * النشر هنا وحده: موعدٌ «مؤكد»، ووقتٌ ورابطٌ على الاستشارة، وتذكرةٌ «موعد مؤكد» وبطاقة الموعد
 * في محادثتها. والبريد والتقويم والإشعارات بعد الالتزام (`HandleAppointmentPublished`).
 *
 * الحمولة (يحسمها `ConsultAppointments::publish`): `lawyer_id` · `lawyer` · `starts_at` ·
 * `duration` · `day` · `time` · `type` · `place` · `zoom` (نتيجة Zoom أو null) ·
 * `changes` (ما عدّلته الإدارة على الاقتراح) · `proposer_id`.
 *
 * @extends Transition<Consult>
 */
final class PublishAppointment extends Transition
{
    private ?TicketMessage $card = null;

    private bool $ticketMoved = false;

    public function name(): string
    {
        return 'consult.publish-appointment';
    }

    public function from(): array
    {
        return [ConsultStatus::AwaitingSchedule->value, ConsultStatus::AwaitingAppointmentApproval->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return ConsultStatus::New->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor !== null && $actor->isAdmin() ? null : 'اعتماد موعد الاستشارة ونشره للإدارة العليا.';
    }

    public function guard(Model $entity, array $payload): ?string
    {
        if (! isset($payload['starts_at'])) {
            return null; // `allowed()` يسأل بلا حمولة
        }

        return $payload['starts_at']->isPast() ? 'لا يمكن اعتماد موعدٍ في الماضي، فضلاً اختر وقتاً لاحقاً.' : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $meta = ConsultBooking::meta((string) $payload['type']);
        $lawyerId = (int) $payload['lawyer_id'];
        $startsAt = $payload['starts_at'];
        $duration = (int) $payload['duration'];

        $appointment = $entity->appointment;
        if ($appointment !== null && $appointment->status !== AppointmentStatus::PendingApproval->value) {
            $appointment = null; // موعدٌ قديم منتهٍ أو ملغى — لا يُعاد إحياؤه
        }

        // **الاقتراح لا يتعارض مع نفسه:** يُخلى وقته قبل فحص التعارض، وتُرجعه المعاملة إن رُفض.
        $appointment?->update(['starts_at' => null]);
        ConsultBooking::guardNoConflict($lawyerId, $startsAt, $duration);

        $values = [
            'user_id' => $entity->user_id,
            'ticket_id' => $entity->ticket_id,
            'type' => 'استشارة '.$meta['label'],
            'ico' => $meta['ico'],
            'lawyer' => (string) $payload['lawyer'],
            'lawyer_id' => $lawyerId,
            'day' => (string) $payload['day'],
            'time' => (string) $payload['time'],
            'starts_at' => $startsAt,
            'duration_min' => $duration,
            'place' => (string) $payload['place'],
            'status' => AppointmentStatus::Confirmed->value,
            'tone' => 'b-green',
            'when_kind' => 'up',
        ];

        if ($appointment !== null) {
            $appointment->update($values);
        } else {
            $appointment = Appointment::create($values + ['ext_id' => 'AP-'.now()->format('y').'-'.random_int(1000, 9999)]);
        }

        $zoom = is_array($payload['zoom'] ?? null) ? $payload['zoom'] : null;
        $entity->forceFill([
            'appointment_id' => $appointment->id,
            'channel' => $meta['label'],
            'lawyer' => (string) $payload['lawyer'],
            'assigned_lawyer_id' => $lawyerId,
            'day' => (string) $payload['day'],
            'time' => (string) $payload['time'],
            'starts_at' => $startsAt,
            'duration_min' => $duration,
            'when_label' => $payload['day'].' · '.$payload['time'],
            'meet_id' => $zoom['id'] ?? null,
            'meet_link' => $zoom['join_url'] ?? null,
            'host_link' => $zoom['start_url'] ?? null,
            'meet_password' => $zoom['password'] ?? null,
        ]);
        $entity->logAudit(
            $actor->name ?? 'النظام',
            'اعتماد الموعد',
            '—',
            $payload['day'].' · '.$payload['time'].' — '.$payload['lawyer']
                .(($payload['changes'] ?? []) !== [] ? ' (بعد تعديل الإدارة)' : '')
        );

        $ticket = $entity->ticket;
        if ($ticket === null || TicketStatus::tryFrom((string) $ticket->status)?->isFinal() === true) {
            return;
        }

        $this->card = $ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => 'مواعيد',
            'body' => AppointmentCard::render($entity, $meta),
            'time_label' => now()->format('h:i').' '.(now()->hour < 12 ? 'ص' : 'م'),
        ]);

        $ticket->update([
            'status' => TicketStatus::Scheduled->value,
            'tone' => TicketJourney::toneFor(TicketStatus::Scheduled->value),
            'last_message' => 'تم تأكيد موعد الاستشارة: '.$payload['day'].' · '.$payload['time'],
            'date_label' => 'الآن',
        ]);
        $this->ticketMoved = true;
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        return [new AppointmentPublished(
            consult: $entity,
            actorId: $actor?->id,
            proposerId: isset($payload['proposer_id']) ? (int) $payload['proposer_id'] : null,
            changes: is_array($payload['changes'] ?? null) ? array_values($payload['changes']) : [],
            card: $this->card,
            ticketMoved: $this->ticketMoved,
        )];
    }

    public function record(array $payload): array
    {
        return [
            'lawyer_id' => $payload['lawyer_id'] ?? null,
            'day' => $payload['day'] ?? null,
            'time' => $payload['time'] ?? null,
            'type' => $payload['type'] ?? null,
            'changes' => $payload['changes'] ?? [],
        ];
    }
}
