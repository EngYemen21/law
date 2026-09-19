<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **أُحيل الملفّ للمستشار بملخّصه ⇐ «بانتظار اعتماد المستشار».**
 *
 * يناديه `TicketTriage::referToLawyer` (زرّ «الإحالة» عند الموظّف). كان يكتب الحالة مباشرةً وبلا
 * فحص، والمنادي الوحيد يشترط أربعاً من مراحل ما قبل اعتماد المستشار («جديدة» · «قيد التحليل» ·
 * «محالة» · «بانتظار مستندات»).
 *
 * `from()` مفتوحةٌ (`ANY`) لأنّ `referToLawyer` عامّة وكانت تكتب من أيّ حالة — ومنها حالاتٌ نصّيّة
 * قديمة خارج الكتالوج تحملها صفوفٌ قائمة. والحارس يسدّ ما لم يكن ممكناً من المنادي أصلاً: حالةٌ
 * **من الكتالوج** بعد اعتماد المستشار — فالإحالة لا تُرجع ملفّاً اعتُمد ملخّصه (ع١١) ولا تُحيي
 * ملفّاً حُسم مآله. ومراحل ما قبل الاعتماد الخمس مقبولة (الخامسة لإعادة إحالةٍ بعد النواقص).
 *
 * الحمولة: `lawyer_id` · `lawyer_name` — المحامي المسند إن وُجد؛ وبلا محامٍ يبقى الإسناد القائم.
 *
 * @extends Transition<Ticket>
 */
final class ReferTicketToLawyer extends Transition
{
    public const LAST_MESSAGE = 'تمت الإحالة، ويُعدّ ملخص الملف لاعتماد المستشار';

    public function name(): string
    {
        return 'ticket.referred_to_lawyer';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Ticket $entity */
        $known = TicketStatus::tryFrom((string) $entity->status) !== null;

        return $known && ! in_array($entity->status, TicketJourney::BEFORE_LAWYER_APPROVAL, true)
            ? 'تجاوز الملفّ مرحلة الإحالة — لا يُحال بعد اعتماد المستشار لملخّصه.'
            : null;
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::AwaitingLawyerApproval->value;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        // بلا محامٍ (لا متخصّص فصُعّد للإدارة) يبقى ما في الصفّ — كما كان `?: $ticket->assigned_lawyer`
        if (! empty($payload['lawyer_id'])) {
            $entity->assigned_lawyer_id = (int) $payload['lawyer_id'];
        }
        if (filled($payload['lawyer_name'] ?? null)) {
            $entity->assigned_lawyer = (string) $payload['lawyer_name'];
        }
        $entity->tone = TicketJourney::toneFor(TicketStatus::AwaitingLawyerApproval->value);
        $entity->last_message = self::LAST_MESSAGE;
        // لا `date_label = 'الآن'`: كلمةٌ تتجمّد لحظة الكتابة، والبطاقة تشتقّ الوقت من `updated_at`
    }

    public function record(array $payload): array
    {
        return array_filter(['lawyer_id' => $payload['lawyer_id'] ?? null]);
    }
}
