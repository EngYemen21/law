<?php

namespace App\Domain\Journey\Transitions\LegalCase;

use App\Domain\Journey\Transition;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **قرار الإدارة العليا في طلب فتح التنفيذ** — اعتمادٌ (يُفتح الملفّ بعده عبر `ExecutionCreation::fromCase`)
 * أو رفضٌ بسبب. كلاهما يطوي الطلب القائم ويُسجَّل سطراً باسمه في سجلّ الانتقالات.
 *
 * @extends Transition<LegalCase>
 */
final class DecideCaseExecutionRequest extends Transition
{
    public function __construct(private readonly bool $approve) {}

    public function name(): string
    {
        return $this->approve ? 'case.approve_execution_request' : 'case.reject_execution_request';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return (string) $entity->getAttribute('status');
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor !== null && ! $actor->isAdmin() ? 'البتّ في طلب التنفيذ للإدارة العليا وحدها.' : null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var LegalCase $entity */
        if ($entity->execution_requested_at === null) {
            return 'لا طلب تنفيذٍ قائم على هذه القضية — ربّما بُتّ فيه، حدّث الصفحة.';
        }
        if (! $this->approve && mb_strlen(trim((string) ($payload['reason'] ?? ''))) < 5) {
            return 'اكتب سبب رفض الطلب (5 أحرف على الأقل).';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var LegalCase $entity */
        $entity->forceFill(['execution_requested_at' => null, 'execution_requested_by' => null, 'execution_request_reason' => null, 'execution_request_amount' => null, 'execution_request_defendant' => null]);
    }

    public function record(array $payload): array
    {
        return array_filter(['reason' => $payload['reason'] ?? null, 'requested_by' => $payload['requested_by'] ?? null, 'request_reason' => $payload['request_reason'] ?? null, 'request_amount' => $payload['request_amount'] ?? null, 'request_defendant' => $payload['request_defendant'] ?? null]);
    }
}
