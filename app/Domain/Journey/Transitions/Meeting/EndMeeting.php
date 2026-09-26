<?php

namespace App\Domain\Journey\Transitions\Meeting;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Domain\Journey\Transition;
use App\Events\Journey\SessionEndedInSystem;
use App\Events\MeetingStatusBroadcast;
use App\Events\RoomStateChanged;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **إنهاء الاجتماع ⇐ «منتهٍ» — الحدث الوحيد الذي يُنهي اجتماعاً.**
 *
 * قرار المالك (2026-09-26): الاجتماع بلا مدّةٍ ثابتة، ينتهي حين يُنهى. وكان الإنهاء مكتوباً في
 * ثلاثة مواضع بنسخٍ متقاربة (زرّ الطاقم `Staff\MeetingController::end` · ويبهوك Zoom
 * `meeting.ended` · المجدول)، والرابعُ حسبةٌ في العرض تُعلن «منتهٍ» بعد «المدة + 120د». الآن
 * كتابةٌ واحدة: الحالة، والحضور المُدخَل يدوياً إن وُجد، ورفعُ الدعوة المرتبطة إلى «نُفّذت».
 *
 * **`from()` مفتوح والحارس يفرّق بالمصدر:** Zoom دليلُ انعقاد، فيُختم به حتى اجتماعٌ وُسم
 * «لم ينعقد» قبله؛ أمّا زرّ الطاقم وشبكة النسيان فلا يُنهيان إلّا **جارياً** (`Meeting::isLive`)
 * — كان الزرّ يُنهي اجتماعاً «قادماً» لم يبدأ فيُكتب «منتهٍ» ويُطلب محضرُ ما لم ينعقد. و«منتهٍ»
 * و«ملغى» لا يُعاد ختمهما من أيّ مصدر (حدثٌ متأخّر/مكرّر).
 *
 * **والبثّ وإغلاقُ غرفة Zoom حدثان بعد الالتزام** (`SessionEndedInSystem` ⇒ `EndZoomMeetingJob`،
 * قرار المالك 2026-09-26) — إلّا إن جاء الإنهاء من Zoom نفسه (`source` = `zoom`) فالغرفة أُغلقت
 * هناك. وتوليد الملخّص يبقى عند المنادي.
 *
 * الحمولة (اختياريّة): `attend` (نسبةٌ مُدخلة يدوياً) · `source` (`staff` افتراضاً / `zoom` / `safety_net`
 * — `SessionEndedInSystem::VIA_*`) · `reason`.
 *
 * @extends Transition<Meeting>
 */
final class EndMeeting extends Transition
{
    public function name(): string
    {
        return 'meeting.end';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return MeetingStatus::Ended->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Meeting $entity */
        if (MeetingStatus::isFinalValue($entity->status)) {
            return 'انتهى هذا الاجتماع أو أُلغي بالفعل.';
        }

        return ($payload['source'] ?? SessionEndedInSystem::VIA_STAFF) !== SessionEndedInSystem::VIA_ZOOM && ! $entity->isLive()
            ? 'لم يبدأ هذا الاجتماع — لا يُنهى إلّا اجتماعٌ جارٍ. إن فات موعده فأعد جدولته أو ألغِه.'
            : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Meeting $entity */
        // المُدخَل يدوياً يُحترم؛ وإلّا 0 = غير مسجَّل (كانت 90 مختلقة تُعرض كنسبةٍ حقيقيّة)
        $entity->attend = isset($payload['attend']) ? (int) $payload['attend'] : ($entity->attend ?: 0);

        MeetRequest::where('meeting_id', $entity->id)
            ->where('stage', '<', MeetRequest::STAGE_EXECUTED)
            ->update(['stage' => MeetRequest::STAGE_EXECUTED]);
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        /** @var Meeting $entity */
        return [
            new MeetingStatusBroadcast($entity),
            ...SessionEndedInSystem::forEnd($entity->meet_id, $entity->ref, $payload),
            ...RoomStateChanged::both($entity),
        ];
    }

    public function record(array $payload): array
    {
        return ['source' => $payload['source'] ?? SessionEndedInSystem::VIA_STAFF];
    }
}
