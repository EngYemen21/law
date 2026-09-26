<?php

namespace App\Domain\Journey\Transitions\Meeting;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Domain\Journey\Transition;
use App\Models\JourneyTransition;
use App\Models\Meeting;
use Illuminate\Database\Eloquent\Model;

/**
 * **تنبيهٌ باجتماعٍ منسيّ — مرّةً واحدة، ولا تتغيّر حالته.** (قرار المالك 2026-09-26)
 *
 * نظير `Consult\AlertStaleSession`: اجتماعٌ بدأ ولم يُنهه أحد بعد `session_stale_minutes` يبقى
 * «جارٍ» حتى يُنهيه الطاقم أو حدث Zoom (`EndMeeting`)، ويُنبَّه الطاقم وحده. سطرُ هذا الانتقال
 * في سجلّ الرحلة هو ذاكرة التنبيه، والحارس يرفض الثاني تحت قفل الصفّ.
 *
 * `from()` مفتوح لأنّ «بدأ» ليس حالةً واحدة: «جارٍ»، أو «قادم» سجّل Zoom دخول أحد أطرافه وضاع
 * حدث البدء (`Meeting::hasStarted`) — والحارس يقول ما المرفوض.
 *
 * @extends Transition<Meeting>
 */
final class AlertStaleMeeting extends Transition
{
    public function name(): string
    {
        return 'meeting.stale_alerted';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return (string) $entity->status;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Meeting $entity */
        // «لم ينعقد» محسومٌ لا منسيّ — وإن لم يكن نهائيّاً (يُعاد جدولته)
        if (MeetingStatus::isFinalValue($entity->status)
            || $entity->status === MeetingStatus::Missed->value
            || ! $entity->hasStarted()) {
            return 'لا تنبيه إلّا لاجتماعٍ بدأ ولم يُنهَ.';
        }

        return JourneyTransition::happened($entity, $this->name())
            ? 'نُبّه الطاقم بهذا الاجتماع من قبل.'
            : null;
    }

    public function record(array $payload): array
    {
        return array_filter([
            'started_at' => $payload['started_at'] ?? null,
            'after_minutes' => $payload['after_minutes'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
