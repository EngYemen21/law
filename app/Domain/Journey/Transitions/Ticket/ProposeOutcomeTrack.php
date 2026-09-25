<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Enums\Role;
use App\Events\TicketStatusBroadcast;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit;
use App\Support\Notify;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **رفع مقترح مآل التذكرة (أحد المسارات الـ4) للإدارة العليا للاعتماد.**
 *
 * يُتاح للمحامي والموظف لرفع التوصية المهنية مع التسبيب الحقيقي
 * ولا يُنشر للعميل إلا بعد صدور قرار الإدارة العليا المعتمد.
 *
 * ولا يُرفع مقترحٌ قبل ملخّصٍ معتمد — إلّا للإدارة العليا بسبب تجاوزٍ مدوَّن (`OutcomeSummaryGate`).
 *
 * @extends Transition<Ticket>
 */
final class ProposeOutcomeTrack extends Transition
{
    /** سبب التجاوز إن مضى الانتقال به — يُحسب في `apply` ويقرؤه `record` و`events` بعده. */
    private ?string $waived = null;

    public function name(): string
    {
        return 'ticket.propose_outcome_track';
    }

    public function from(): array
    {
        return [
            TicketStatus::New->value,
            TicketStatus::Analyzing->value,
            TicketStatus::AwaitingDocs->value,
            TicketStatus::Referred->value,
            TicketStatus::AwaitingLawyerApproval->value,
            TicketStatus::AwaitingAdminSummaryApproval->value,
            TicketStatus::LegalOpinion->value,
            TicketStatus::AwaitingBooking->value,
            TicketStatus::AwaitingSchedule->value,
            TicketStatus::Scheduled->value,
            TicketStatus::AwaitingSessionSummary->value,
            TicketStatus::ReadyForOutcome->value,
            TicketStatus::AwaitingAdminOutcomeApproval->value,
            TicketStatus::Completed->value,
        ];
    }

    public function to(Model $entity, array $payload): string
    {
        return TicketStatus::AwaitingAdminOutcomeApproval->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        if (! ($actor->isAdmin() || $actor->isLawyer() || $actor->isEmployee())) {
            return 'صلاحية رفع مقترح المسار محصورة في فريق العمل المختص.';
        }

        return null;
    }

    public function denyRequest(Model $entity, ?User $actor, array $payload): ?string
    {
        return OutcomeSummaryGate::denyWaiver($actor, $payload);
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Ticket $entity */
        if ($entity->is_frozen) {
            return 'التذكرة مجمدة بقرار نهائي سابق ولا يمكن تغيير مسارها.';
        }

        $track = $payload['track'] ?? null;
        if (! is_string($track) || ! in_array($track, TicketOutcomeTrack::values(), true)) {
            return 'يجب اختيار مسار صالح من المسارات المعتمدة الأربعة (استشارة، قضية، تنفيذ، إلغاء).';
        }

        $reason = trim((string) ($payload['reason'] ?? ''));
        if (mb_strlen($reason) < 10) {
            return 'يجب تدوين السبب الحقيقي والمبرر المهني للمسار المقترح (10 أحرف على الأقل).';
        }

        // ث٥: الشرط في الانتقال لا في المتحكّمات الثلاثة — يسري على كلّ منادٍ حاضرٍ وآتٍ
        if (($why = OutcomeSummaryGate::guard($entity, $payload)) !== null) {
            return $why;
        }

        /*
         * **تكرارُ المقترح نفسه يُردّ — لا تغييرُه.**
         *
         * الانتقال يقبل `AwaitingAdminOutcomeApproval` في `from()` عمداً: للموظّف أن يصحّح
         * اقتراحه إلى مسارٍ آخر قبل أن تعتمده الإدارة. لكنّ الحارس كان يفحص ما **لا يكتبه**
         * الانتقال (التجميد · صحّة المسار · طول التسبيب)، فنقرتان متزامنتان تمرّان كلتاهما:
         * فيُسجَّل انتقالان متطابقان وتصل الإدارةَ إشعاران عن قرارٍ واحد (قيس حيّاً 2026-09-25).
         *
         * والفحص هنا لا في المتحكّم: ثلاثة متحكّمات تنادي هذا الانتقال (الموظّف · المحامي ·
         * الإدارة)، والقاعدة في الانتقال تسري عليها وعلى أيّ منادٍ يأتي.
         *
         * ويعمل لأنّ `Workflow::run` يقفل الصفّ **ويعيد قراءته** قبل الحارس، فالطلب الثاني
         * يرى مقترح الأوّل مكتوباً. وهو النمط نفسه الذي جعل اعتماد المآل والتسعير منيعَين.
         */
        if ($entity->status === TicketStatus::AwaitingAdminOutcomeApproval->value
            && $entity->proposed_track === $track) {
            return 'هذا المسار مرفوعٌ أصلاً وبانتظار اعتماد الإدارة العليا — اختر مساراً آخر إن أردت تصحيح المقترح.';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        $trackEnum = TicketOutcomeTrack::from((string) $payload['track']);
        $reason = trim((string) $payload['reason']);
        $this->waived = OutcomeSummaryGate::usedWaiver($entity, $payload);

        $entity->proposed_track = $trackEnum->value;
        $entity->proposed_track_reason = $reason;
        $entity->proposed_by_id = $actor?->id;
        $entity->proposed_at = now();

        $entity->status = TicketStatus::AwaitingAdminOutcomeApproval->value;
        $entity->tone = TicketJourney::toneFor(TicketStatus::AwaitingAdminOutcomeApproval->value);
        $entity->last_message = 'رُفع مقترح المسار ('.$trackEnum->label().') إلى الإدارة العليا للاعتماد.';
        $entity->date_label = 'الآن';
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        /** @var Ticket $entity */
        $trackEnum = TicketOutcomeTrack::from((string) $payload['track']);
        $actorName = $actor?->name ?? 'أحد أعضاء الفريق';

        foreach (User::where('role', Role::Admin)->pluck('id') as $adminId) {
            Notify::send(
                $adminId,
                'scale',
                't-amber',
                "رفع {$actorName} مقترح مسار للتذكرة {$entity->number}: «{$trackEnum->label()}» — بانتظار الاعتماد النهائي."
            );
        }

        Audit::log(
            action: 'اقتراح مسار مآل التذكرة',
            description: "رفع {$actorName} مقترح مسار ({$trackEnum->label()}) للتذكرة {$entity->number} مع التسبيب.",
            category: 'تذاكر',
            auditable: $entity,
            auditableRef: $entity->number,
            user: $actor,
        );

        if ($this->waived !== null) {
            OutcomeSummaryGate::audit($entity, $actor, 'رفع مقترح المسار', $this->waived);
        }

        return [new TicketStatusBroadcast($entity)];
    }

    public function record(array $payload): array
    {
        return OutcomeSummaryGate::record($this->waived);
    }
}
