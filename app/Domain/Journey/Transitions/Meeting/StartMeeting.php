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
 * **بدء الاجتماع ⇐ «جارٍ» — نظير `EndMeeting` في البداية.**
 *
 * كان «جارٍ» يُكتب مباشرةً في ثلاثة مواضع خارج المحرّك، كلٌّ بشرطه: زرّ الطاقم
 * (`Staff\MeetingController::start` — من «قادم/مؤجل»)، وبدء تنفيذ الدعوة
 * (`Staff\MeetRequestController::start` — بلا شرطٍ على حالة الاجتماع إطلاقاً، فيُحيي «ملغى»)،
 * وويبهوك Zoom `meeting.started` (مقارنةٌ نصّيّة بـ«منتهٍ/ملغى»). ولا سطرَ في سجلّ الرحلة
 * يقول متى بدأ ومن بدأه — وشبكة النسيان تقيس من البدء. هنا كتابةٌ واحدة بحارسٍ واحد.
 *
 * **المصدر يفرّق:** الطاقم يبدأ ما هو «قادم/مؤجل» وحده؛ وZoom دليلُ انعقاد فيُقبل من أيّ حالةٍ
 * غير نهائيّة — ولو «لم ينعقد» (حضر الطرفان متأخّرَين). والنهائيّتان مرفوضتان من الجميع: حدثٌ
 * متأخّر لا يُحيي اجتماعاً أُلغي.
 *
 * الحمولة: `source` (`staff` افتراضاً / `zoom`).
 *
 * @extends Transition<Meeting>
 */
final class StartMeeting extends Transition
{
    public function name(): string
    {
        return 'meeting.start';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return MeetingStatus::Live->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Meeting $entity */
        $status = MeetingStatus::tryFrom((string) $entity->status);

        if ($status?->isFinal() === true) {
            return 'انتهى هذا الاجتماع أو أُلغي — لا يُبدأ من جديد.';
        }
        if ($status === MeetingStatus::Live) {
            return 'بدأ هذا الاجتماع بالفعل.';
        }
        if (($payload['source'] ?? SessionEndedInSystem::VIA_STAFF) === SessionEndedInSystem::VIA_ZOOM) {
            return null;
        }

        return in_array($status, [MeetingStatus::Upcoming, MeetingStatus::Postponed], true)
            ? null
            : 'لا يُبدأ إلّا اجتماعٌ قادم — إن فات موعده فأعد جدولته.';
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        // بدء الاجتماع تنفيذٌ لدعوته — كانت الدعوة تُرفع هنا في مسارٍ وتُنسى في آخر
        MeetRequest::where('meeting_id', $entity->getKey())
            ->where('stage', '<', MeetRequest::STAGE_EXECUTED)
            ->update(['stage' => MeetRequest::STAGE_EXECUTED]);
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        /** @var Meeting $entity */
        return [new MeetingStatusBroadcast($entity), ...RoomStateChanged::both($entity)];
    }

    public function record(array $payload): array
    {
        return ['source' => $payload['source'] ?? SessionEndedInSystem::VIA_STAFF];
    }
}
