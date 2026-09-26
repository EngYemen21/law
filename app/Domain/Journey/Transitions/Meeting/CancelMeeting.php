<?php

namespace App\Domain\Journey\Transitions\Meeting;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Domain\Journey\Transition;
use App\Events\Journey\MeetingCancelled;
use App\Events\MeetingStatusBroadcast;
use App\Events\RoomStateChanged;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **إلغاء الاجتماع ⇐ «ملغى» — من زرّ الاجتماع أو من إلغاء دعوته.** (قرار المالك 2026-09-26)
 *
 * كان «ملغى» يُكتب مباشرةً في `Staff\MeetingController::cancel` بلا سطرٍ في سجلّ الرحلة، وبلا حارسٍ
 * إلّا «غير نهائيّ» — فيُلغى اجتماعٌ **جارٍ** والطرفان في غرفته. وكان إلغاء **الدعوة**
 * (`MeetRequestController::cancel`) لا يمسّ اجتماعها أصلاً: يبقى «قادماً» في اجتماعات العميل
 * وغرفته حيّة على Zoom. الآن بابان وكتابةٌ واحدة:
 *
 * - **الحارس:** النهائيّ لا يُلغى، و**الجاري لا يُلغى** (`Meeting::isLive`) — يُنهى أوّلاً.
 * - **الكتابة:** «ملغى» + تصفير `meet_id` (غرفةٌ محذوفة لا يوجَّه إليها ويبهوك متأخّر) + الدعوات
 *   المرتبطة «أُلغيت» — إلّا المعتمدة: تنزيلها يمحو سجلّ اعتمادها بلا رجعة.
 * - **بعد الالتزام:** البثّ، و`MeetingCancelled` يحذف الغرفة في الطابور.
 *
 * الحمولة (اختياريّة): `via` (`meeting` افتراضاً / `invitation`).
 *
 * @extends Transition<Meeting>
 */
final class CancelMeeting extends Transition
{
    /** معرّف الغرفة قبل تصفيره — يُلتقط في `apply` ليحمله الحدث بعد الالتزام. */
    private ?string $oldMeetId = null;

    public function name(): string
    {
        return 'meeting.cancel';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return MeetingStatus::Cancelled->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Meeting $entity */
        if (MeetingStatus::isFinalValue($entity->status)) {
            return 'انتهى هذا الاجتماع أو أُلغي بالفعل.';
        }

        return $entity->isLive()
            ? 'الاجتماع جارٍ الآن — أنهِه أوّلاً ثمّ ألغِه إن لزم.'
            : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Meeting $entity */
        $this->oldMeetId = filled($entity->meet_id) ? (string) $entity->meet_id : null;
        $entity->meet_id = null;

        MeetRequest::where('meeting_id', $entity->getKey())
            ->where('stage', '!=', MeetRequest::STAGE_APPROVED)
            ->update(['stage' => MeetRequest::STAGE_CANCELLED]);
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        /** @var Meeting $entity */
        return [
            new MeetingStatusBroadcast($entity),
            ...RoomStateChanged::both($entity),
            new MeetingCancelled($entity, $this->oldMeetId, $actor, $this->via($payload)),
        ];
    }

    public function record(array $payload): array
    {
        return ['via' => $this->via($payload)];
    }

    /** @param  array<string, mixed>  $payload */
    private function via(array $payload): string
    {
        return ($payload['via'] ?? null) === MeetingCancelled::VIA_INVITATION
            ? MeetingCancelled::VIA_INVITATION
            : MeetingCancelled::VIA_MEETING;
    }
}
