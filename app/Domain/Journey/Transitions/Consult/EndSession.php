<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\AppointmentStatus;
use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Transition;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **ختم الجلسة ⇐ «منتهية» — من زرّ الطاقم أو من ويبهوك Zoom.**
 *
 * كان الموضعان (`Staff\ConsultController::end` و`ZoomWebhookController::endConsult`) يكتبان
 * الجلسة والحالة وموعدها مباشرةً، كلٌّ بنسخته. هنا الكتابة واحدة: الجلسة والحالة «منتهية»،
 * والموعد المرتبط يُحسم «تم الحضور» (وإلّا بقي «قادماً» في الأعمدة المخزّنة حتى تنقضي خانته).
 *
 * **`from()` مفتوح والحارس «غير مختومة» وحده، عمداً:** الويبهوك يختم من أيّ جلسةٍ لم تُختم
 * (Zoom دليلُ انعقاد، ولو وُسمت «لم تُعقد» قبله)؛ وزرّ الطاقم يشترط «جارية» عند المنادي
 * برسالته. تضييقه هنا يُسقط ختماً ينجح اليوم.
 *
 * ما يلي الختم (تذكرة «بانتظار ملخّص الجلسة»، وإنهاء غرفة Zoom، والتوليد، والبثّ) يبقى عند
 * المنادي بعد النداء وبترتيبه — شبكةٌ وطوابير لا مكان لها داخل المعاملة.
 *
 * الحمولة (اختياريّة، من زرّ الطاقم): `session_notes` · `duration_label`.
 *
 * @extends Transition<Consult>
 */
final class EndSession extends Transition
{
    public function name(): string
    {
        return 'consult.end';
    }

    public function column(): string
    {
        return 'session';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return SessionState::Ended->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        return $entity->session === SessionState::Ended->value
            ? 'خُتمت هذه الجلسة بالفعل.'
            : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Consult $entity */
        $entity->status = ConsultStatus::Ended->value;

        if (array_key_exists('session_notes', $payload)) {
            $entity->session_notes = $payload['session_notes'];
        }
        if (array_key_exists('duration_label', $payload)) {
            $entity->duration_label = $payload['duration_label'];
        }

        $entity->appointment?->update([
            'when_kind' => 'past',
            'status' => AppointmentStatus::Attended->value,
            'tone' => 'b-green',
        ]);
    }

    public function record(array $payload): array
    {
        return ['source' => $payload['source'] ?? 'staff'];
    }
}
