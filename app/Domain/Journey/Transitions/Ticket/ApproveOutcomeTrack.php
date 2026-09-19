<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\ClosureReasonCode;
use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit;
use App\Support\CaseConversion;
use App\Support\ExecutionCreation;
use App\Support\Live;
use App\Support\Notify;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **اعتماد الإدارة العليا لمسار مآل التذكرة النهائي (أحد المسارات الأربعة).**
 *
 * القرار سيادي وحصري للإدارة العليا:
 * - استشارة: نقل التذكرة لبانتظار حجز الاستشارة وإرسال إشعار ورسالة بالسبب الحقيقي للعميل.
 * - قضية: تحويل الطلب إلى ملف قضية وتجميد التذكرة ونشر القرار والسبب للعميل.
 * - تنفيذ: تحويل الطلب إلى ملف تنفيذ قضائي وتجميد التذكرة ونشر القرار والسبب للعميل.
 * - إغلاق: حفظ مسبب للتذكرة برمز سبب معتمد وتجميد التذكرة ونشر المبرر الحقيقي للعميل.
 *
 * @extends Transition<Ticket>
 */
final class ApproveOutcomeTrack extends Transition
{
    public function name(): string
    {
        return 'ticket.approve_outcome_track';
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
        $track = TicketOutcomeTrack::tryFrom((string) ($payload['track'] ?? ''));

        return match ($track) {
            TicketOutcomeTrack::Consultation => TicketStatus::AwaitingBooking->value,
            TicketOutcomeTrack::Case => TicketStatus::ConvertedToCase->value,
            TicketOutcomeTrack::Execution => TicketStatus::ConvertedToCase->value,
            TicketOutcomeTrack::Close => TicketStatus::Closed->value,
            default => TicketStatus::Closed->value,
        };
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        if (! $actor->isAdmin()) {
            return 'صلاحية اعتماد المسار النهائي للتذكرة حصرية للإدارة العليا فقط.';
        }

        return null;
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
            return 'يجب تدوين السبب الحقيقي والمبرر النظامي الذي يظهر للعميل (10 أحرف على الأقل).';
        }

        return null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Ticket $entity */
        $trackEnum = TicketOutcomeTrack::from((string) $payload['track']);
        $reason = trim((string) $payload['reason']);

        $entity->approved_track = $trackEnum->value;
        $entity->approved_track_reason = $reason;
        $entity->approved_by_id = $actor?->id;
        $entity->approved_track_at = now();
        $entity->outcome_decision_at = now();
        $entity->date_label = 'الآن';

        match ($trackEnum) {
            TicketOutcomeTrack::Consultation => $this->applyConsultation($entity, $actor, $reason),
            TicketOutcomeTrack::Case => $this->applyCase($entity, $actor, $reason),
            TicketOutcomeTrack::Execution => $this->applyExecution($entity, $actor, $reason),
            TicketOutcomeTrack::Close => $this->applyClose($entity, $actor, $payload, $reason),
        };
    }

    private function applyConsultation(Ticket $entity, ?User $actor, string $reason): void
    {
        $entity->status = TicketStatus::AwaitingBooking->value;
        $entity->tone = TicketJourney::toneFor(TicketStatus::AwaitingBooking->value);
        $entity->last_message = 'اعتمدت الإدارة العليا مسار طلب استشارة قانونية.';

        $body = '<div class="notice-box notice-blue">'
            .'<p><strong>قرار الإدارة العليا: توجيه بطلب استشارة قانونية</strong></p>'
            .'<p><strong>السبب والمبرر النظامي:</strong> '.nl2br(e($reason)).'</p>'
            .'<p>نأمل منكم حجز موعد الاستشارة من خلال قسم «حجز استشارة» لعقد الجلسة ومناقشة تفاصيل الملف مع المستشار المختص.</p>'
            .'</div>';

        $msg = $entity->messages()->create([
            'who' => 'admin',
            'name' => $actor?->name ?? 'الإدارة العليا',
            'role' => 'اعتماد المسار',
            'body' => $body,
            'time_label' => self::clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));

        Notify::send(
            $entity->user_id,
            'scale',
            't-blue',
            "اعتمدت الإدارة العليا مسار تذكرتك {$entity->number}: طلب استشارة قانونية. يمكنك الآن حجز الموعد."
        );
    }

    private function applyCase(Ticket $entity, ?User $actor, string $reason): void
    {
        $entity->status = TicketStatus::ConvertedToCase->value;
        $entity->tone = TicketJourney::toneFor(TicketStatus::ConvertedToCase->value);
        $entity->is_frozen = true;
        $entity->last_message = 'اعتمدت الإدارة العليا مسار التحويل لقضية رسمية.';

        $caseRef = '';
        if (! $entity->legalCase()->exists() && $actor !== null) {
            $case = CaseConversion::fromTicket($entity, $actor, $reason);
            $caseRef = $case->number;
        } else {
            $caseRef = (string) $entity->legalCase()->value('number');
        }

        $body = '<div class="notice-box notice-green">'
            .'<p><strong>قرار الإدارة العليا: تحويل الطلب إلى قضية رسمية</strong></p>'
            .'<p><strong>السبب والمبرر النظامي:</strong> '.nl2br(e($reason)).'</p>'
            .'<p>تم قيد ملف القضية'.($caseRef ? " برقم <b>{$caseRef}</b>" : '').' وإحالته للفريق القضائي المختص لمباشرة الإجراءات والترافع، ويمكنكم متابعة المستجدات عبر قسم «القضايا».</p>'
            .'</div>';

        $msg = $entity->messages()->create([
            'who' => 'admin',
            'name' => $actor?->name ?? 'الإدارة العليا',
            'role' => 'اعتماد المسار',
            'body' => $body,
            'time_label' => self::clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));

        Notify::send(
            $entity->user_id,
            'scale',
            't-cyan',
            "اعتمدت الإدارة العليا مسار تذكرتك {$entity->number}: تحويل لقضية رسمية".($caseRef ? " برقم {$caseRef}" : '').'. تابعها من «القضايا».'
        );
    }

    private function applyExecution(Ticket $entity, ?User $actor, string $reason): void
    {
        $entity->status = TicketStatus::ConvertedToCase->value;
        $entity->tone = 'b-amber';
        $entity->is_frozen = true;
        $entity->last_message = 'اعتمدت الإدارة العليا مسار التحويل لملف تنفيذ قضائي.';

        $exec = null;
        if (! $entity->execution()->exists() && $actor !== null) {
            $exec = ExecutionCreation::fromTicket($entity, $actor, $reason);
        }

        $execRef = $exec?->number ?? ($entity->execution()->value('number') ?? '');
        $body = '<div class="notice-box notice-amber">'
            .'<p><strong>قرار الإدارة العليا: تحويل الطلب إلى ملف تنفيذ قضائي</strong></p>'
            .'<p><strong>السبب والمبرر النظامي:</strong> '.nl2br(e($reason)).'</p>'
            .'<p>تم فتح ملف التنفيذ'.($execRef ? " برقم <b>{$execRef}</b>" : '').' وإحالته لقسم التنفيذ لمباشرة الإجراءات لدى محكمة التنفيذ، ويمكنكم متابعته عبر قسم «طلبات التنفيذ».</p>'
            .'</div>';

        $msg = $entity->messages()->create([
            'who' => 'admin',
            'name' => $actor?->name ?? 'الإدارة العليا',
            'role' => 'اعتماد المسار',
            'body' => $body,
            'time_label' => self::clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));
    }

    private function applyClose(Ticket $entity, ?User $actor, array $payload, string $reason): void
    {
        $reasonCode = (string) ($payload['closure_reason_code'] ?? ClosureReasonCode::NoLegalMerit->value);
        if (! in_array($reasonCode, ClosureReasonCode::values(), true)) {
            $reasonCode = ClosureReasonCode::NoLegalMerit->value;
        }

        $entity->status = TicketStatus::Closed->value;
        $entity->tone = TicketJourney::toneFor(TicketStatus::Closed->value);
        $entity->is_frozen = true;
        $entity->closure_reason_code = $reasonCode;
        $entity->closure_notes = $reason;
        $entity->closed_by_id = $actor?->id;
        $entity->last_message = 'أُغلقت التذكرة بعد دراسة الطلب بقرار مسبّب من الإدارة العليا.';

        $reasonEnum = ClosureReasonCode::tryFrom($reasonCode) ?? ClosureReasonCode::NoLegalMerit;
        $body = '<div class="notice-box notice-grey">'
            .'<p><strong>قرار الإدارة العليا: إغلاق وحفظ الطلب بقرار مسبّب</strong></p>'
            .'<p><strong>تصنيف سبب الإغلاق:</strong> '.e($reasonEnum->label()).'</p>'
            .'<p><strong>المبرر والسبب النظامي:</strong> '.nl2br(e($reason)).'</p>'
            .'<p>تم حفظ ملف الطلب رسمياً بناءً على الأسباب الموضحة أعلاه، ومخرجات المعالجة محفوظة ومتاحة للرجوع إليها.</p>'
            .'</div>';

        $msg = $entity->messages()->create([
            'who' => 'admin',
            'name' => $actor?->name ?? 'الإدارة العليا',
            'role' => 'اعتماد المسار',
            'body' => $body,
            'time_label' => self::clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));

        Notify::send(
            $entity->user_id,
            'check',
            't-grey',
            "أُغلقت تذكرتك {$entity->number} بقرار مسبّب من الإدارة العليا. مخرجات ومبررات القرار محفوظة داخل التذكرة."
        );
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        /** @var Ticket $entity */
        $trackEnum = TicketOutcomeTrack::from((string) $payload['track']);
        $actorName = $actor?->name ?? 'الإدارة العليا';

        Audit::log(
            action: 'اعتماد مسار مآل التذكرة',
            description: "اعتمدت {$actorName} مسار ({$trackEnum->label()}) للتذكرة {$entity->number} مع التسبيب.",
            category: 'تذاكر',
            auditable: $entity,
            auditableRef: $entity->number,
            user: $actor,
        );

        return [new TicketStatusBroadcast($entity)];
    }

    private static function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
