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
 * **«لم ينعقد» — اجتماعٌ فات موعده ولم يبدأ قطّ** — يناديه `zoom:auto-close-missed` بعد مهلة الإعدادات.
 *
 * كشفُ غيابٍ من **البداية** لا إنهاءُ جلسة: ما بدأ (حالته «جارٍ» أو سجّل Zoom دخولاً) يرفضه
 * الحارس، فذاك لا ينتهي إلّا بـ`EndMeeting` (قرار المالك 2026-09-26). وكان المجدول يكتب الحالة
 * مباشرةً، ويختم «منتهٍ» كلَّ قادمٍ دخله أحدٌ بمجرّد مرور اثنتي عشرة ساعة على موعده.
 *
 * دعوته المرتبطة تُعلَّم «منتهية الصلاحية» — يُتاح إعادة إرسالها بموعدٍ جديد.
 *
 * @extends Transition<Meeting>
 */
final class MarkMeetingMissed extends Transition
{
    public function name(): string
    {
        return 'meeting.missed';
    }

    public function from(): array
    {
        return [
            MeetingStatus::Upcoming->value,
            MeetingStatus::Postponed->value,
            MeetingStatus::AwaitingConfirmation->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return MeetingStatus::Missed->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Meeting $entity */
        if ($entity->hasStarted()) {
            return 'بدأ هذا الاجتماع — لا يُوسَم «لم ينعقد»، ويُنهى بإنهائه.';
        }

        return $entity->isMissed() ? null : 'لم يفت موعد هذا الاجتماع بعد.';
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        MeetRequest::where('meeting_id', $entity->getKey())
            ->where('stage', '<', MeetRequest::STAGE_EXECUTED)
            ->update(['stage' => MeetRequest::STAGE_EXPIRED]);
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        /** @var Meeting $entity */
        // وغرفةُ Zoom تُغلق كما في الإنهاء — إغلاقٌ لا حذف: «لم ينعقد» يُعاد جدولته على الاجتماع
        // نفسه (`BookingMoved::apply` يحدّثه)، فحذفُه يترك إعادة الجدولة بلا غرفة.
        return [
            new MeetingStatusBroadcast($entity),
            ...SessionEndedInSystem::forEnd($entity->meet_id, $entity->ref, $payload),
            ...RoomStateChanged::both($entity),
        ];
    }

    public function record(array $payload): array
    {
        return ['automatic' => true];
    }
}
