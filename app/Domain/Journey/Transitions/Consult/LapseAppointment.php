<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Transition;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **حسم موعدٍ فات وقته ⇐ ما تشتقّه `Appointment::liveState()`** — يناديه `appointments:auto-lapse`.
 *
 * كان الأمر يكتب الحالة المشتقّة مباشرةً خارج المحرّك. الحالة الهدف لا يقرّرها الانتقال بل
 * `liveState()` (مصدرٌ واحد للحقيقة بين البطاقة والحسم)، فتصل في الحمولة: `status` و`tone`.
 *
 * `from()` مفتوح عمداً: الأمر كان يكتب بلا شرطٍ على الحالة (سوى استبعاد «بانتظار الاعتماد»
 * في استعلامه، ويبقى هناك)، والحسم آليّ لا يجوز أن يتعثّر بحالةٍ قديمة لا يعرفها التعداد.
 *
 * @extends Transition<Appointment>
 */
final class LapseAppointment extends Transition
{
    public function name(): string
    {
        return 'appointment.lapse';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return (string) ($payload['status'] ?? $entity->status);
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Appointment $entity */
        $entity->when_kind = 'past';
        $entity->tone = (string) ($payload['tone'] ?? $entity->tone);
    }

    public function record(array $payload): array
    {
        return ['automatic' => true];
    }
}
