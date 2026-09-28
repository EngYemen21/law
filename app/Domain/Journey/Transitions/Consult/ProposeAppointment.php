<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\AppointmentStatus;
use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Transition;
use App\Events\Journey\AppointmentProposed;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\User;
use App\Support\ConsultBooking;
use App\Support\ReferenceNumber;
use Illuminate\Database\Eloquent\Model;

/**
 * **الموظّف يقترح موعد الجلسة — ولا يصل العميلَ شيء** (قرار المالك 2026-09-14).
 *
 * يُنشأ موعدٌ «بانتظار الاعتماد» يحجز خانة المحامي (فلا يُقترح عليها موعدٌ ثانٍ)، ولا يُكتب
 * على الاستشارة وقتٌ ولا رابط: فتبقى خارج التذكيرات وإطلاق الروابط وبطاقات العميل حتى تعتمد
 * الإدارة الموعد وتنشره (`PublishAppointment`).
 *
 * الحمولة (يحسمها `ConsultAppointments::propose`): `lawyer_id` · `lawyer` · `starts_at` ·
 * `duration` · `day` · `time` · `type` (office|video|phone) · `place`.
 *
 * @extends Transition<Consult>
 */
final class ProposeAppointment extends Transition
{
    private ?Appointment $appointment = null;

    /** قُبل الموعد فوق انشغالٍ آخر للمحامي (خيار الحجز المتداخل) — يُسجَّل في الرحلة ويُنبَّه به الحاجز. */
    private bool $overlap = false;

    public function name(): string
    {
        return 'consult.propose-appointment';
    }

    public function from(): array
    {
        return [ConsultStatus::AwaitingSchedule->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return ConsultStatus::AwaitingAppointmentApproval->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return match (true) {
            $actor === null => 'اقتراح الموعد فعلٌ بشريّ.',
            $actor->isAdmin() => 'الإدارة تعتمد الموعد وتنشره مباشرةً.',
            default => null,
        };
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $meta = ConsultBooking::meta((string) $payload['type']);

        $this->overlap = ConsultBooking::guardNoConflict((int) $payload['lawyer_id'], $payload['starts_at'], (int) $payload['duration']);

        $this->appointment = Appointment::create([
            'user_id' => $entity->user_id,
            'ticket_id' => $entity->ticket_id,
            // مفتاح المسار (`Appointment::getRouteKeyName`) — المولّد الموحّد يمنع رقمين لموعدين فيُفتح غير المقصود
            'ext_id' => ReferenceNumber::next(Appointment::class, 'ext_id', 'AP'),
            'type' => 'استشارة '.$meta['label'],
            'ico' => $meta['ico'],
            'lawyer' => (string) $payload['lawyer'],
            'lawyer_id' => (int) $payload['lawyer_id'],
            'day' => (string) $payload['day'],
            'time' => (string) $payload['time'],
            'starts_at' => $payload['starts_at'],
            'duration_min' => (int) $payload['duration'],
            'place' => (string) $payload['place'],
            'status' => AppointmentStatus::PendingApproval->value,
            'tone' => 'b-amber',
            'when_kind' => 'up',
        ]);

        $entity->appointment_id = $this->appointment->id;
        $entity->logAudit(
            $actor->name ?? 'النظام',
            'اقتراح موعد',
            '—',
            "{$payload['day']} · {$payload['time']} — {$payload['lawyer']} (بانتظار اعتماد الإدارة)"
        );
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        return $this->appointment === null ? [] : [new AppointmentProposed($entity, $this->appointment, $actor->name ?? 'النظام')];
    }

    /** قُبل الموعد فوق انشغالٍ آخر للمحامي — يقرؤه `ConsultAppointments` بعد `Workflow::run`. */
    public function overlapped(): bool
    {
        return $this->overlap;
    }

    public function record(array $payload): array
    {
        return [
            'lawyer_id' => $payload['lawyer_id'] ?? null,
            'day' => $payload['day'] ?? null,
            'time' => $payload['time'] ?? null,
            'type' => $payload['type'] ?? null,
            'overlap' => $this->overlap,
        ];
    }
}
