<?php

namespace App\Domain\Journey\Transitions\Conversation;

use App\Domain\Journey\Transition;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **تولّي محادثة الملفّ** — التذكرة أو القضيّة أو التنفيذ. (طلب المالك 2026-09-25)
 *
 * «الموظّف لا يُحتفظ بمن الذي أدار المحادثات»: لم يكن للمحادثة مسؤولٌ ولا سجلّ. وقرّر المالك:
 * **التولّي تلقائيّ** — من يردّ من الطاقم يصير مسؤولها، و**ردُّ زميلٍ ينقلها إليه**. فيُطلق هذا
 * الانتقال من `ConversationHandler::noteMessage` حين يردّ غيرُ المسؤول الحاليّ.
 *
 * **حلقةٌ على الذات** (نمط `Execution\AssignExecutionLawyer`): الحالة لا تتغيّر، والكتابة في
 * `handler_id`. والسجلّ سطرٌ في `journey_transitions` باسم `conversation.taken_over` لكلّ انتقال —
 * بالفاعل والوقت ومن قبله، في معاملة الرسالة نفسها وبقفل الصفّ: ردّان متزامنان من موظّفَين سطران
 * مرتّبان لا تضارب.
 *
 * الحمولة: `via` — نوع الرسالة التي نقلت المسؤوليّة (ردّ · مستند · طلب مستندات).
 *
 * @extends Transition<Model>
 */
final class TakeOverConversation extends Transition
{
    public const NAME = 'conversation.taken_over';

    private ?int $fromId = null;

    private ?string $fromName = null;

    private ?string $toName = null;

    public function name(): string
    {
        return self::NAME;
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return (string) $entity->getAttribute($this->column());
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        // يتولّى الطاقمُ المحادثةَ لا العميل ولا الآليّ — ودورُ المحامي إسنادُه المستقلّ (`assigned_lawyer_id`)
        return $actor !== null && ($actor->isEmployee() || $actor->isAdmin())
            ? null
            : 'تولّي المحادثة للموظّف أو الإدارة.';
    }

    /**
     * **المسؤول نفسه لا يتولّى ما يتولّاه.** يُفحص هنا — بعد القفل على صفٍّ مُعاد قراءته — لا قبله:
     * ردّان متزامنان من الموظّف نفسه يريان قبل القفل أنّه ليس المسؤول، فيكتب الثاني «تولّاها فلان
     * بعد فلان» عن نفسه. والفاعل في الحمولة (`by`) لأنّ الحارس لا يرى الفاعل.
     */
    public function guard(Model $entity, array $payload): ?string
    {
        if (! isset($payload['by'])) {
            return null; // `allowed()` يسأل بلا حمولة
        }

        return (int) $entity->getAttribute('handler_id') === (int) $payload['by']
            ? 'يتولّى هذه المحادثة أصلاً.'
            : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $this->fromId = $entity->getAttribute('handler_id') !== null ? (int) $entity->getAttribute('handler_id') : null;
        $this->fromName = $this->fromId !== null ? User::whereKey($this->fromId)->value('name') : null;
        $this->toName = $actor?->name;

        $entity->setAttribute('handler_id', $actor?->id);
    }

    public function record(array $payload): array
    {
        return [
            'from_id' => $this->fromId,
            'from_name' => $this->fromName,
            'to_name' => $this->toName,
            'via' => $payload['via'] ?? null,
        ];
    }
}
