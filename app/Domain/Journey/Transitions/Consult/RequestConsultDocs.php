<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Transition;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **طلب استكمال مستند ⇐ «بانتظار استكمال البيانات».**
 *
 * كان `Staff\ConsultController::requestDocs` يكتب الحالة مباشرةً. حارساه (دورة الحجز والنهايات)
 * يبقيان عند المنادي برسالتيهما، ويُكرَّران هنا شبكةَ أمان: طلبُ مستندٍ على طلبٍ لم يُسعَّر يُسقطه
 * من طابور التسعير (حادثة CN-2026-4504)، وعلى ملفٍّ منتهٍ يُحييه.
 *
 * `from()` مفتوح والحارس هو الفيصل — كما كان المتحكّم: كلُّ حالةٍ خارج دورة الحجز والنهايات
 * مقبولة، بما فيها «لم يحضر» والحالات القديمة التي لا يعرفها التعداد.
 *
 * الحمولة: `docs` — نصّ المستند المطلوب (يُضاف إلى `missing` بلا تكرار).
 *
 * @extends Transition<Consult>
 */
final class RequestConsultDocs extends Transition
{
    public function name(): string
    {
        return 'consult.request_docs';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return ConsultStatus::AwaitingData->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        if (in_array($entity->status, Consult::PRE_SESSION_STATUSES, true)) {
            return 'الطلب ما زال في دورة الحجز — أكمل التسعير والسداد قبل طلب المستندات.';
        }

        return in_array($entity->status, Consult::CLOSED_STATUSES, true)
            ? 'الاستشارة انتهت أو أُلغيت — لا تُطلب لها مستندات.'
            : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Consult $entity */
        $missing = $entity->missing ?? [];
        $missing[] = trim((string) ($payload['docs'] ?? ''));
        $entity->missing = array_values(array_unique($missing));

        $entity->logAudit($actor->name ?? 'النظام', 'الحالة', (string) $entity->status, ConsultStatus::AwaitingData->value);
    }

    public function record(array $payload): array
    {
        return ['docs' => isset($payload['docs']) ? trim((string) $payload['docs']) : null];
    }
}
