<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Transition;
use App\Events\RoomStateChanged;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **Zoom يُعلن بدء الاجتماع ⇐ «جلسة جارية» / «قيد الاستشارة».**
 *
 * غيرُ `StartSession` عمداً: ذاك فعلُ الطاقم ويحرسه `isStartable` (نافذة الموعد ودورة الحجز)؛
 * وهذا **دليلٌ** من Zoom على انعقادٍ وقع فعلاً فيُقبل من أيّ جلسةٍ لم تُختم — ولو وُسمت
 * «لم يحضر» قبله (ينبّه المنادي الإدارةَ حينها). ولا يُطلق إشعار «بدأت جلستك» للعميل:
 * الويبهوك لم يكن يُطلقه.
 *
 * `from()` مفتوح كما كان الويبهوك يكتب؛ والمختومة وحدها مرفوضة (حدثٌ متأخّر لا يُحيي جلسةً
 * خُتمت). والمنادي يتجاوز «جارية أصلاً» بصمتٍ قبل النداء.
 *
 * @extends Transition<Consult>
 */
final class ZoomSessionStarted extends Transition
{
    public function name(): string
    {
        return 'consult.zoom_started';
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
        return SessionState::Live->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        return $entity->session === SessionState::Ended->value
            ? 'خُتمت هذه الجلسة — لا يُحييها حدثٌ متأخّر.'
            : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Consult $entity */
        $entity->status = ConsultStatus::InSession->value;
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        /** @var Consult $entity */
        return RoomStateChanged::both($entity);
    }
}
