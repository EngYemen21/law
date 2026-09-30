<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Transition;
use App\Events\Journey\ConsultSessionStarted;
use App\Events\RoomStateChanged;
use App\Models\Consult;
use App\Models\User;
use App\Support\SessionWindow;
use Illuminate\Database\Eloquent\Model;

/**
 * **بدء الجلسة — لطلبٍ نُشر موعده فقط.**
 *
 * كان `isStartable` يقبل `starts_at` الفارغ ولا يستبعد دورة الحجز، فتُبدأ جلسةٌ لطلبٍ
 * «بانتظار التسعير» ويصل العميلَ «بدأت جلسة استشارتك» ثمّ تُنهى ويولَّد ملخّصها بلا
 * تسعير ولا سداد (ع٤). الحارس الآن `Consult::isStartable` نفسه — مصدرٌ واحد للبطاقة والخادم.
 *
 * @extends Transition<Consult>
 */
final class StartSession extends Transition
{
    public function name(): string
    {
        return 'consult.start';
    }

    public function column(): string
    {
        return 'session';
    }

    public function from(): array
    {
        return [SessionState::Waiting->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return SessionState::Live->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        if ($entity->isStartable()) {
            return null;
        }

        if (ConsultStatus::tryFrom((string) $entity->status)?->isPreSession()) {
            return 'لم يُحجز لهذه الاستشارة موعدٌ منشور بعد — أكمل التسعير والسداد والحجز أوّلاً.';
        }

        return $entity->isMissed()
            ? 'فات موعد هذه الجلسة — سجّل «لم يحضر» أو أعد جدولتها.'
            : 'الجلسة تُبدأ قبل موعدها بـ'.SessionWindow::staffStartLabel().' فأقرب.';
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $entity->status = ConsultStatus::InSession->value;
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        /** @var Consult $entity */
        // وصفحةُ الغرفة المفتوحة تصير «جارية» ويُفعَّل زرّ الإنهاء لحظة البدء
        return [new ConsultSessionStarted($entity), ...RoomStateChanged::both($entity)];
    }
}
