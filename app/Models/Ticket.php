<?php

namespace App\Models;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\GuardsJourneyState;
use App\Domain\Journey\Transitions\Ticket\OutcomeSummaryGate;
use App\Infrastructure\Repositories\EloquentTicketRepository;
use App\Models\Concerns\ClipsPreviewText;
use App\Models\Concerns\LinksLegalDepartment;
use App\Models\Concerns\PurgesDocumentFiles;
use App\Support\LawyerName;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    use ClipsPreviewText, PurgesDocumentFiles;
    use GuardsJourneyState;
    use LinksLegalDepartment;

    /** سطر المعاينة في بطاقات القوائم — varchar(255) يستقبل نصّ المستخدم بلا سقف. */
    protected array $previewText = ['last_message'];

    protected $fillable = [
        'user_id', 'number', 'type', 'subject', 'opponent_name', 'opponent_id', 'claim_amount', 'exec_sanad', 'court_name', 'priority',
        'department', 'assigned_lawyer', 'assigned_lawyer_id', 'status', 'tone', 'attachments', 'last_message', 'date_label',
        'legal_department_id', 'legal_service_id',
        'closure_reason_code', 'closure_notes', 'closed_by_id', 'is_frozen', 'outcome_decision_at',
        'ai_suggested_track', 'ai_suggested_reason',
        'proposed_track', 'proposed_track_reason', 'proposed_by_id', 'proposed_at',
        'approved_track', 'approved_track_reason', 'approved_by_id', 'approved_track_at',
    ];

    protected function casts(): array
    {
        return [
            'is_frozen' => 'boolean',
            'outcome_decision_at' => 'datetime',
            'proposed_at' => 'datetime',
            'approved_track_at' => 'datetime',
        ];
    }

    /** القسم في `department` والخدمة في `type` — يُربطان بالكتالوج عند الحفظ. */
    protected function legalDepartmentSource(): string
    {
        return 'department';
    }

    protected function legalServiceSource(): ?string
    {
        return 'type';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** الموظّف المسؤول عن محادثة هذا الملفّ الآن — يتولّاها تلقائيّاً من يردّ (`ConversationHandler`). */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handler_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    public function summary(): HasOne
    {
        return $this->hasOne(TicketSummary::class);
    }

    // المستندات المرفوعة مع نتيجة فحصها الذكي
    public function documents(): HasMany
    {
        return $this->hasMany(TicketDocument::class)->orderBy('id');
    }

    // حساب المحامي المسند (المصدر الموثوق؛ العمود النصي للعرض فقط)
    public function assignedLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_lawyer_id');
    }

    public function legalCase(): HasOne
    {
        return $this->hasOne(LegalCase::class);
    }

    public function execution(): HasOne
    {
        return $this->hasOne(Execution::class);
    }

    /**
     * **الملفّ الذي تشهد له حالة التذكرة** — القضيّة وراء «محولة إلى قضية»، والتنفيذ وراء
     * «محولة إلى تنفيذ»، ولا شيء لغيرهما.
     *
     * المصدر الواحد لسؤال «هل حالة هذه التذكرة قائمةٌ على ملفٍّ حيّ؟» — يقرؤه حارس التصحيح
     * الإداريّ (`CorrectTicketStatus`) في الاتّجاهين: لا يُصحَّح **بعيداً** عن حالةٍ ملفُّها قائم،
     * ولا **إلى** حالةٍ لا ملفَّ وراءها.
     *
     * والربط بالحالة لا بمجرّد وجود صفّ: القضيّة قد يُفتح منها تنفيذٌ يحمل `ticket_id` نفسه
     * (`ExecutionCreation::fromCase`)، فتذكرةٌ حالتها «محولة إلى قضية» لها صفّا ملفّين — وما
     * يُثبّت حالتها هو القضيّة. ولو حُذف ملفّ التنفيذ وبقيت القضيّة، فتصحيح «محولة إلى تنفيذ»
     * إلى «محولة إلى قضية» هو الإصلاح الصحيح ولا يجوز أن يُصدّ.
     *
     * «قائم» = الصفّ موجودٌ ومربوطٌ بالتذكرة، **أيّاً كانت حالته** (مغلقاً أو مؤرشفاً): الملفّ
     * المغلق يبقى ما صارت إليه التذكرة فعلاً — تُفتح منه القضيّة ثانيةً (`ReopenCase`) ويُفتح
     * منه التنفيذ — والعميل يقرأ حالة التذكرة دليلاً إليه. ولا حذف ناعماً على الجدولين، فحذف
     * الصفّ يُفرغ `ticket_id` (مفتاح `nullOnDelete`) ويعود التصحيح ممكناً.
     */
    public function producedFile(): LegalCase|Execution|null
    {
        return $this->fileBehind(TicketStatus::tryFrom((string) $this->status));
    }

    /** الملفّ الذي **كانت** ستشهد له هذه الحالة لو بلغتها التذكرة — القاعدة نفسها لـ`producedFile`. */
    public function fileBehind(?TicketStatus $status): LegalCase|Execution|null
    {
        return match ($status) {
            TicketStatus::ConvertedToCase => $this->legalCase,
            TicketStatus::ConvertedToExecution => $this->execution,
            default => null,
        };
    }

    // استشارات هذه التذكرة (تُنشأ عند طلب حجز استشارة من داخل المحادثة)
    /** @return HasMany<Consult, $this> */
    public function consults(): HasMany
    {
        return $this->hasMany(Consult::class);
    }

    /** رسالة العميل الأولى نصّاً (تُحفظ HTML بـ`nl2br(e())`) — وقائع طلبه كما كتبها عند الفتح. */
    public function openingText(): string
    {
        $first = $this->messages()->where('who', 'client')->first();

        return $first instanceof TicketMessage ? trim(html_entity_decode(strip_tags((string) $first->body))) : '';
    }

    /** طلبُ استشارةٍ قائم لم تنعقد جلسته — طلبٌ واحد لكلّ تذكرة حتى يكتمل أو يُلغى. */
    public function hasPendingConsult(): bool
    {
        return $this->consults()->whereIn('status', Consult::PRE_SESSION_STATUSES)->exists();
    }

    // ربط المسار برقم التذكرة بدل المعرّف
    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /** حزمة حوكمة مآل التذكرة ومقترح الذكاء للواجهات */
    public function trackGovernance(): array
    {
        return [
            'aiSuggestedTrack' => $this->ai_suggested_track,
            'aiSuggestedReason' => $this->ai_suggested_reason,
            'proposedTrack' => $this->proposed_track,
            'proposedTrackReason' => $this->proposed_track_reason,
            'proposedBy' => $this->relationLoaded('proposedBy') ? $this->proposedBy?->name : $this->proposedBy()->value('name'),
            'proposedAt' => $this->proposed_at?->format('Y-m-d H:i'),
            'approvedTrack' => $this->approved_track,
            'approvedTrackReason' => $this->approved_track_reason,
            'approvedBy' => $this->relationLoaded('approvedBy') ? $this->approvedBy?->name : $this->approvedBy()->value('name'),
            'approvedTrackAt' => $this->approved_track_at?->format('Y-m-d H:i'),
            // سبب تعطيل رفع المقترح/الاعتماد من مصدر الحارس نفسه — لا تعيد البطاقة اشتقاقه (ث٥)
            'outcomeBlocker' => OutcomeSummaryGate::blocker($this),
            // استشارةٌ قائمة تمنع القرار كلّه — مانعٌ بلا تجاوز، فلا تعرض له البطاقة حقل سبب
            'consultBlocker' => OutcomeSummaryGate::consultBlocker($this),
            // سبب التجاوز الذي دوّنه هذا المشاهِد حين رفع المقترح — فلا تطلبه البطاقة ثانيةً
            'inheritedWaiver' => OutcomeSummaryGate::inheritedWaiver($this, auth()->user()),
        ];
    }

    // الشكل الذي تتوقعه واجهة العميل (يطابق DATA.tickets مع مؤشرات الرحلة والوثائق)
    public function toCard(): array
    {
        $hasCase = $this->relationLoaded('legalCase') ? (bool) $this->legalCase : $this->legalCase()->exists();
        $caseNo = $hasCase ? ($this->relationLoaded('legalCase') ? $this->legalCase?->number : $this->legalCase()->value('number')) : null;
        $hasExec = $this->relationLoaded('execution') ? (bool) $this->execution : $this->execution()->exists();
        $execNo = $hasExec ? ($this->relationLoaded('execution') ? $this->execution?->number : $this->execution()->value('number')) : null;

        $entity = app(EloquentTicketRepository::class)->toEntity($this);
        $actions = $entity->actionsMatrix($hasCase, $hasExec);

        return [
            'no' => $this->number,
            'type' => $this->type,
            'subject' => $this->subject,
            'priority' => $this->priority ?: 'متوسطة',
            'dept' => $this->department,
            // بطاقة العميل وحده (`TicketController`): تسميته لا حالة الاعتماد الداخليّة
            'status' => TicketStatus::labelForClient($this->status),
            'statusCode' => $entity->status()->name,
            'actions' => $actions,
            'phase' => TicketJourney::clientPhase((string) $this->status),
            'tone' => $this->tone,
            'last' => $this->last_message,
            // «الآن» المخزّنة كانت تتجمّد للأبد — الاشتقاق الحيّ من آخر تحديث (العمود يبقى للتوافق)
            'date' => $this->updated_at?->locale('ar')->diffForHumans() ?? $this->date_label,
            // بطاقة العميل: «الاسم. الحرف» لمحامٍ مسنَد، والنائبُ كما هو (LawyerName)
            'lawyer' => LawyerName::forClient($this->assigned_lawyer_id ? $this->assignedLawyer : null, $this->assigned_lawyer, 'المستشار المخصص'),
            'step' => TicketJourney::indexOf($this->status),
            'needsDoc' => in_array($this->status, ['بانتظار مستندات', TicketStatus::AwaitingDocs->value], true),
            'needsBooking' => in_array($this->status, ['بانتظار حجز الاستشارة', TicketStatus::AwaitingBooking->value], true),
            'hasCase' => $hasCase,
            'caseNumber' => $caseNo,
            'hasExecution' => $hasExec,
            'executionNumber' => $execNo,
            'courtName' => $this->court_name,
            'claimAmount' => $this->claim_amount,
            'opponentName' => $this->opponent_name,
            'documentsCount' => $this->relationLoaded('documents') ? $this->documents->count() : $this->documents()->count(),
            'messagesCount' => $this->relationLoaded('messages') ? $this->messages->count() : $this->messages()->count(),
            'createdAt' => $this->created_at?->format('Y-m-d'),
            'isFrozen' => (bool) $this->is_frozen,
            'isTerminal' => $entity->isTerminal(),
            'trackGovernance' => $this->publishedTrackDecision(),
        ];
    }

    /**
     * **ما نُشر للعميل من قرار المآل — وحده.**
     *
     * كانت بطاقة العميل تحمل `trackGovernance()` كاملةً (منذ 2026-09-19): اسمَ من رفع المقترح ومن
     * اعتمده **كاملاً** (والمحامي يراه العميل «محمد. ب» بقرار 2026-09-11)، واقتراحَ الذكاء
     * الداخليّ وتسبيبَه، وتسبيبَ المقترح قبل الاعتماد، وموانعَ الحوكمة. وشاشة العميل لا تقرأ منها
     * إلّا القرارَ المعتمد وتسبيبَه المنشور — «نُشر القرار والتسبيب للعميل». فالحمولة على قدر ذلك.
     *
     * @return array{approvedTrack: ?string, approvedTrackReason: ?string}
     */
    public function publishedTrackDecision(): array
    {
        return [
            'approvedTrack' => $this->approved_track,
            'approvedTrackReason' => $this->approved_track ? $this->approved_track_reason : null,
        ];
    }

    // الشكل الذي تتوقعه واجهة الموظف (يطابق SYS_TICKETS) — اسم العميل صريح (قرار 2026-09-11)
    public function toEmployeeCard(): array
    {
        $hasCase = $this->relationLoaded('legalCase') ? (bool) $this->legalCase : $this->legalCase()->exists();
        $caseNo = $hasCase ? ($this->relationLoaded('legalCase') ? $this->legalCase?->number : $this->legalCase()->value('number')) : null;
        $hasExec = $this->relationLoaded('execution') ? (bool) $this->execution : $this->execution()->exists();
        $execNo = $hasExec ? ($this->relationLoaded('execution') ? $this->execution?->number : $this->execution()->value('number')) : null;

        $entity = app(EloquentTicketRepository::class)->toEntity($this);
        $actions = $entity->actionsMatrix($hasCase, $hasExec);

        return [
            'no' => $this->number,
            'client' => self::maskClient($this->user?->name ?? ''),
            'clientId' => $this->user_id,
            'type' => $this->type,
            'subject' => $this->subject,
            'priority' => $this->priority ?: 'متوسطة',
            'dept' => $this->department,
            'lawyer' => $this->assigned_lawyer ?: '—',
            'lawyerId' => $this->assigned_lawyer_id,
            'status' => $this->status,
            'statusCode' => $entity->status()->name,
            // مرحلة «مسار المعالجة» من الخادم — كانت الواجهة تشتقّها من نصّ الحالة العربيّ (`tktStage`)
            'step' => TicketJourney::indexOf($this->status),
            'actions' => $actions,
            'tone' => $this->tone,
            'isFrozen' => (bool) $this->is_frozen,
            'hasCase' => $hasCase,
            'caseNumber' => $caseNo,
            'hasExecution' => $hasExec,
            'executionNumber' => $execNo,
            // الاسم نفسه الذي تقرؤه صفحتا المحادثة وبطاقة المآل — كان `closureReason` فتقرأ الصفحةُ
            // `closureReasonCode` غير المرسَل وتعرض البطاقةُ سبباً افتراضيّاً بدل السبب المعتمد
            'closureReasonCode' => $this->closure_reason_code,
            'closureNotes' => $this->closure_notes,
            'canDecideOutcome' => in_array($this->status, [TicketStatus::ReadyForOutcome->value, TicketStatus::Completed->value], true) && ! $hasCase && ! $hasExec,
            'isTerminal' => $entity->isTerminal(),
            'isReassignable' => $this->isReassignable(),
            // الموظّف المسؤول عن المحادثة الآن — للطاقم وحده (بطاقة العميل `toCard` لا تحمله)
            'handler' => $this->relationLoaded('handler') ? $this->handler?->name : $this->handler()->value('name'),
            'trackGovernance' => $this->trackGovernance(),
        ];
    }

    // اسم العميل للعرض — صريحٌ للإدارة والمحامي والموظّف (قرار المالك 2026-09-11)
    public static function maskClient(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '—') {
            return $name ?: '—';
        }

        /*
         * **ولا تقنيعَ على الإدارة العليا** (قرار المالك 2026-09-08).
         *
         * كانت خمسةُ متحكّماتٍ إداريّة تنادي هذه الدالّة، فيرى المديرُ «ع••••ه (مشفّر)»
         * في شاشةٍ **يملك فيها الملفَّ كلَّه**: يُنزّل نصّه التفريغيّ بأسماء المتحدّثين
         * صريحةً، ويوزّعه، ويعتمد ملخّصه. تقنيعٌ لا يحمي أحداً ويُعمي صاحب القرار —
         * وكان تناقضاً موثَّقاً في `DEPLOYMENT_AR.md` بانتظار الحسم.
         *
         * والحارس هنا لا في نقاط النداء: الموضعُ الواحد يمنع أن يُنسى أحدُها.
         */
        // **ولا تقنيعَ على المحامي والموظّف كذلك** (قرار المالك 2026-09-11): ثلاثتُهم يعملون
        // على ملفّ العميل نفسه، والاسمُ المقنَّع لا يحمي أحداً ويُعطّل العمل. يبقى التقنيع لغير
        // الطاقم احتياطاً — ولا شاشةَ عميلٍ تعرض اسمَ عميلٍ غيره أصلاً.
        $viewer = auth()->user();
        if ($viewer !== null && ($viewer->isAdmin() || $viewer->isLawyer() || $viewer->isEmployee())) {
            return $name;
        }
        $parts = preg_split('/\s+/', $name);
        $f = $parts[0] ?? '';
        $masked = mb_substr($f, 0, 1).'••••'.mb_substr($f, -1);
        $second = isset($parts[1]) ? ' '.mb_substr($parts[1], 0, 1).'•••' : '';

        return $masked.$second.' (مشفّر)';
    }

    /** النطاق المفتوح: التذاكر النشطة التي لم تبلغ حالة نهائية قطعية */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', TicketStatus::finals());
    }

    /**
     * **ما ينتظر التوزيع أو يقبله:** مفتوحةٌ غيرُ مجمَّدة — المجمَّدة حُسم مسارها فلا يُعاد إسنادها
     * (`DistributeController::assign` يرفضها). شاشة التوزيع وتوزيعها الآليّ ورادار اللوحة يقرؤونه
     * فلا يَعِد الرادار بتذاكر «بانتظار التوزيع» لا تظهر في شاشته.
     */
    public function scopeDistributable(Builder $query): Builder
    {
        return $query->open()->where('is_frozen', false);
    }

    /** النطاق النهائي: التذاكر المكتملة أو المغلقة أو المحولة */
    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', TicketStatus::finals());
    }

    /** هل التذكرة في حالة نهائية استناداً إلى كائن الدومين الصافي */
    public function isTerminal(): bool
    {
        return app(EloquentTicketRepository::class)->toEntity($this)->isTerminal();
    }

    public function isClosed(): bool
    {
        return $this->isTerminal();
    }

    /**
     * **سبب منع «إعادة التحليل الذكي للملخّص»، أو `null` إن جازت** — القاعدة الواحدة لمسارَي
     * الموظّف والمستشار/الإدارة ولزرّيهما (قرار المالك 2026-09-27). كانت ثلاث قواعد مختلفة:
     * مسار الموظّف يقبلها على تذكرةٍ مجمَّدة، ومسار المستشار يمنعها بعد اعتماده، وعلمُ الكيان
     * يشترط «قيد التحليل» ولا ينظر في الملخّص.
     */
    public function summaryRerunBlocker(): ?string
    {
        $summary = $this->summary;

        return match (true) {
            $summary === null => 'لا ملخّص لإعادة تحليله بعد.',
            $summary->isApproved() => 'لا يمكن إعادة تشغيل التحليل لملخّص تم اعتماده رسمياً.',
            $summary->isLawyerApproved() => 'اعتُمد هذا الملخّص من المستشار — لا يُعاد توليده.',
            (bool) $this->is_frozen => 'حُسم مسار التذكرة — لا يُعاد تحليل ملخّصها.',
            default => null,
        };
    }

    /** `scopeOpen` مطبَّقاً على صفٍّ محمَّل — لتصفية مجموعةٍ جُلبت أصلاً دون استعلامٍ ثانٍ. */
    public function isOpen(): bool
    {
        return ! in_array($this->status, TicketStatus::finals(), true);
    }

    /**
     * **يُعاد إسنادها؟** — مفتوحةٌ غير مجمّدة: قاعدة `TicketAssignment::assertReassignable` نفسها، علَماً لزرّ
     * «تحويل» في الواجهة (كان يقيس `isTerminal` فيظهر على «مكتملة» ويردّه الخادم).
     */
    public function isReassignable(): bool
    {
        return ! (bool) $this->is_frozen && $this->isOpen();
    }

    /**
     * **اللون يُحسب من الحالة عند القراءة (`TicketJourney::toneFor`)** — العمود المخزَّن يُكتب مع الانتقال
     * لكنّه لا يُقرأ: كانت حمولاتٌ ترسله خاماً وأخرى تحسبه، وصفوفٌ قديمة تحمل لوناً غير لون
     * حالتها، وشاشة التوزيع تسدّ فراغه بألوانٍ لا يُنتجها الخادم. فكلّ `->tone` الآن هو لون الحالة.
     */
    protected function tone(): Attribute
    {
        return Attribute::get(fn () => TicketJourney::toneFor((string) $this->status));
    }
}
