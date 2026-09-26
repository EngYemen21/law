<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\AppointmentStatus;
use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Transition;
use App\Events\Journey\SessionEndedInSystem;
use App\Events\RoomStateChanged;
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
 * **`from()` مفتوح والحارس يفرّق بالمصدر:** الويبهوك يختم من أيّ جلسةٍ لم تُختم (Zoom دليلُ
 * انعقاد، ولو وُسمت «لم تُعقد» قبله)؛ أمّا زرّ الطاقم وشبكة النسيان فلا يختمان إلّا **جاريةً**
 * (`Consult::isLive` — القاعدة نفسها التي تُفعّل زرّ الإنهاء في عقد الغرفة). كان شرط «جارية»
 * عند المنادي وحده، فأيّ منادٍ جديد يختم جلسةً لم تبدأ.
 *
 * **وإغلاقُ غرفة Zoom حدثٌ بعد الالتزام** (`SessionEndedInSystem` ⇒ `EndZoomMeetingJob`) — في
 * الانتقال لا عند كلّ منادٍ، فلا يُختم سجلٌّ وتبقى غرفته مفتوحة (قرار المالك 2026-09-26)؛ إلّا
 * إن جاء الإنهاء من Zoom نفسه (`source` = `zoom`) فالغرفة أُغلقت هناك.
 *
 * وما يلي الختم غير ذلك (تذكرة «بانتظار ملخّص الجلسة»، والتوليد، والبثّ) يبقى عند المنادي بعد
 * النداء وبترتيبه.
 *
 * الحمولة (اختياريّة): `source` (`staff` افتراضاً / `zoom` / `safety_net` — `SessionEndedInSystem::VIA_*`)
 * · `reason` · ومن زرّ الطاقم `session_notes` · `duration_label`.
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
        /** @var Consult $entity */
        if ($entity->session === SessionState::Ended->value) {
            return 'خُتمت هذه الجلسة بالفعل.';
        }

        return ($payload['source'] ?? SessionEndedInSystem::VIA_STAFF) !== SessionEndedInSystem::VIA_ZOOM && ! $entity->isLive()
            ? 'الجلسة لم تبدأ — لا تُختَم إلّا جلسةٌ جارية. سجّل «لم يحضر» إن فات موعدها.'
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

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        /** @var Consult $entity */
        return [
            ...SessionEndedInSystem::forEnd($entity->meet_id, $entity->ref, $payload),
            ...RoomStateChanged::both($entity),
        ];
    }

    public function record(array $payload): array
    {
        return ['source' => $payload['source'] ?? SessionEndedInSystem::VIA_STAFF];
    }
}
