<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **أُسنِدت التذكرة في بدايتها ⇐ «محالة للقسم القانوني».**
 *
 * الإسناد نفسه (المحامي واسمه) ليس حالةً مراقَبة، لكنّه في الوضع البشريّ (الوكيل الآليّ
 * معطّل) يحمل قفزةً في الحالة: تذكرةٌ «جديدة» أو «قيد التحليل» صار لها من يدرسها، وبقاؤها
 * هناك يجعلها تبدو عالقةً للعميل. كان أربعة كتّاب يكتبون القفزة نفسها مباشرةً بلا قيد:
 * الإسناد الأوّل عند الفتح (`TicketAssignment::assign`)، والتوزيع الآليّ (`AssignTicketJob`)،
 * والتصعيد للإدارة (`EscalateUnassignedTicketJob`)، والإسناد اليدويّ (`DistributeController`).
 * يمرّون الآن جميعاً بـ`TicketAssignment::write` الذي ينادي هذا الانتقال.
 *
 * `from()` هي الحالتان اللتان كان الأربعة يشترطونهما بالضبط؛ وغيرهما يُسنَد بلا قفزة كما كان
 * (يفحص المنادي `accepts()` قبل النداء). والإسناد يُكتب هنا في الحفظ نفسه — لا حالةَ «محالة»
 * بلا محامٍ ولو للحظة.
 *
 * الحمولة: `lawyer_id` · `lawyer_name` (الاسم المعروض — للتصعيد «الإدارة العليا» لا اسم شخص).
 *
 * @extends Transition<Ticket>
 */
final class ReferOnAssignment extends Transition
{
    public const LAST_MESSAGE = 'تمت إحالة طلبكم إلى القسم القانوني المختص لدراسة الموضوع.';

    public function name(): string
    {
        return 'ticket.referred_on_assignment';
    }

    public function from(): array
    {
        return [TicketStatus::New->value, TicketStatus::Analyzing->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::Referred->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        // يُنادى من `Workflow::allowed` بحمولةٍ فارغة — فالمفتاح الغائب ليس رفضاً هناك
        if (array_key_exists('lawyer_id', $payload) && ! $payload['lawyer_id']) {
            return 'لا إحالة بلا جهةٍ مسنَدة.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        $entity->assigned_lawyer_id = (int) $payload['lawyer_id'];
        $entity->assigned_lawyer = (string) $payload['lawyer_name'];
        $entity->tone = TicketJourney::toneFor(TicketStatus::Referred->value);
        $entity->last_message = self::LAST_MESSAGE;
        // لا `date_label = 'الآن'`: كلمةٌ تتجمّد لحظة الكتابة، والبطاقة تشتقّ الوقت من `updated_at`
    }

    public function record(array $payload): array
    {
        return ['lawyer_id' => $payload['lawyer_id'] ?? null];
    }
}
