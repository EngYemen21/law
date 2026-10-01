<?php

namespace App\Models;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\GuardsJourneyState;
use App\Models\Concerns\ClipsPreviewText;
use App\Models\Concerns\LinksLegalDepartment;
use App\Models\Concerns\PurgesDocumentFiles;
use App\Models\Concerns\TracksRevisions;
use App\Support\CaseFee;
use App\Support\CaseJourney;
use App\Support\LawyerName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LegalCase extends Model
{
    use ClipsPreviewText, PurgesDocumentFiles;
    use GuardsJourneyState;
    use LinksLegalDepartment;
    use TracksRevisions;

    /** سطر المعاينة في بطاقات القوائم — varchar(255) يستقبل نصّ المستخدم بلا سقف. */
    protected array $previewText = ['update_text'];

    // Case كلمة محجوزة في PHP، لذا نستخدم LegalCase مع جدول cases
    protected $table = 'cases';

    protected $fillable = [
        'user_id', 'ticket_id', 'number', 'type', 'assigned_lawyer', 'assigned_lawyer_id', 'department', 'status', 'tone',
        'legal_department_id',
        'update_text', 'next_hearing', 'invoice_text', 'paid_text', 'fee', 'fee_status',
        'lawyer_fee', 'lawyer_pct', 'pleading_status', 'ruling',
        'pay_plan', 'installments_total', 'installments_paid',
        // مقترح `case.classify` بانتظار المراجعة — يطبّقه `AiReviewOutcome` عند القبول
        'ai_classification',
        // بيانات الرفع والقيد في ناجز (الخطّة ب — 2026-09-11)
        'najiz_request_no', 'filed_at', 'najiz_case_no', 'court', 'circuit', 'registered_at',
        // تسبيب الإغلاق (المرحلة ب — 2026-09-16)
        'closure_reason', 'closure_notes', 'closed_at',
        // مسار الاستئناف والاعتراض (المرحلة د — 2026-09-16)
        'appeal_status', 'appeal_deadline_at', 'appeal_request_no', 'appeal_court', 'appeal_circuit',
        'appeal_ruling', 'appeal_filed_at', 'appeal_judged_at',
    ];

    protected $casts = [
        'execution_requested_at' => 'datetime',
        'execution_request_amount' => 'integer',
        'ai_classification' => 'array',
        'filed_at' => 'date',
        'registered_at' => 'date',
        'closed_at' => 'datetime',
        'appeal_deadline_at' => 'date',
        'appeal_filed_at' => 'date',
        'appeal_judged_at' => 'date',
    ];

    /** قسم القضيّة في `department` — يُربط بالكتالوج عند الحفظ (ومنه اعتماد تصنيف الذكاء). */
    protected function legalDepartmentSource(): string
    {
        return 'department';
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

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** مبلغ المطالبة في تذكرة القضيّة — اقتراح «المبلغ المحكوم به» في طلب التنفيذ؛ `null` حين لم يُدخَل. */
    public function ticketClaimAmount(): ?int
    {
        $amount = $this->ticket?->getAttribute('claim_amount');

        return $amount === null ? null : (int) $amount;
    }

    // حساب المحامي المسند (المصدر الموثوق؛ العمود النصي للعرض فقط)
    public function assignedLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_lawyer_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CaseMessage::class, 'case_id')->orderBy('id');
    }

    // فواتير أتعاب القضيّة (الكاملة أو أقساطها) — المفتاح case_id
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'case_id');
    }

    public function hearings(): HasMany
    {
        return $this->hasMany(CaseHearing::class, 'case_id')->orderBy('id');
    }

    public function documents(): HasMany
    {
        // **الأحدث أوّلاً.** كانت تصاعديّةً فيظهر المستند المرفوع الآن في ذيل القائمة —
        // وشاشة المحامي وحدها كانت تفرزها في المتصفّح، فترى ثلاثُ شاشاتٍ تعرض العلاقة
        // نفسها ترتيبين مختلفين. والإجراءات والرسائل تبقى تصاعديّةً لأنّها خطٌّ زمنيّ.
        return $this->hasMany(CaseDocument::class, 'case_id')->orderByDesc('id');
    }

    public function execution(): HasOne
    {
        return $this->hasOne(Execution::class, 'case_id');
    }

    // ربط المسار برقم القضية بدل المعرّف
    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /**
     * أقرب جلسة مجدولة **لم يفت موعدها** — عمود next_hearing المخزّن لا يتحدّث بمرور الوقت
     * فكانت «الجلسة القادمة» تعرض جلسة ماضية. يستعمل العلاقة المحمّلة إن وُجدت (تفادي N+1).
     */
    public function nextHearingLive(): ?CaseHearing
    {
        $notPast = fn ($h) => $h->status === 'مجدولة' && ($h->starts_at === null || $h->starts_at->isFuture());

        if ($this->relationLoaded('hearings')) {
            return $this->hearings
                ->filter($notPast)
                ->sortBy([['starts_at', 'asc'], ['id', 'asc']])
                ->first();
        }

        return $this->hearings()->where('status', 'مجدولة')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '>=', now()))
            ->orderByRaw('starts_at IS NULL')
            ->orderBy('starts_at')->orderBy('id')
            ->first();
    }

    /** نصّ «الجلسة القادمة» الحيّ — المخزَّن احتياط لسجلات قديمة/مبذورة بلا صفوف جلسات */
    public function nextHearingLabel(): string
    {
        $next = $this->nextHearingLive();
        if ($next) {
            return $next->label();
        }

        $hasHearings = $this->relationLoaded('hearings')
            ? $this->hearings->isNotEmpty()
            : $this->hearings()->exists();

        // توجد جلسات لكن لا قادمة منها ⇒ «—» صادقة؛ لا جلسات إطلاقاً ⇒ النص المخزّن القديم
        return $hasHearings ? '—' : ((string) $this->next_hearing ?: '—');
    }

    /**
     * بيانات الرفع والقيد في ناجز للعرض — `null` ما لم تُرفع. رقم الطلب يُسجَّل عند الرفع،
     * ورقم القضيّة والمحكمة والدائرة عند القيد (`CaseFiling`).
     *
     * @return array{requestNo: ?string, filedAt: ?string, caseNo: ?string, court: ?string, circuit: ?string, registeredAt: ?string}|null
     */
    public function najizCard(): ?array
    {
        if ($this->najiz_request_no === null && $this->najiz_case_no === null) {
            return null;
        }

        $date = fn ($d) => $d?->locale('ar')->translatedFormat('d F Y');

        return [
            'requestNo' => $this->najiz_request_no,
            'filedAt' => $date($this->filed_at),
            'caseNo' => $this->najiz_case_no,
            'court' => $this->court,
            'circuit' => $this->circuit,
            'registeredAt' => $date($this->registered_at),
        ];
    }

    /**
     * بيانات مسار الاستئناف إن وُجدت.
     *
     * @return array{status: string, statusLabel: string, deadlineAt: ?string, daysRemaining: ?int, isDeadlineOver: bool, requestNo: ?string, court: ?string, circuit: ?string, ruling: ?string, filedAt: ?string, judgedAt: ?string}|null
     */
    public function appealCard(): ?array
    {
        if ($this->appeal_status === null) {
            return null;
        }

        $date = fn ($d) => $d?->locale('ar')->translatedFormat('d F Y');

        return [
            'status' => $this->appeal_status,
            'statusLabel' => match ($this->appeal_status) {
                'pending_appeal' => 'بانتظار الاستئناف / مهلة الاعتراض',
                'appeal_filed' => 'تم قيد الاستئناف',
                'appeal_judged' => 'صدر حكم الاستئناف',
                default => $this->appeal_status,
            },
            'deadlineAt' => $date($this->appeal_deadline_at),
            'daysRemaining' => $this->appeal_deadline_at ? max(0, (int) now()->diffInDays($this->appeal_deadline_at, false)) : null,
            'isDeadlineOver' => $this->appeal_deadline_at ? now()->greaterThan($this->appeal_deadline_at) : false,
            'requestNo' => $this->appeal_request_no,
            'court' => $this->appeal_court,
            'circuit' => $this->appeal_circuit,
            'ruling' => $this->appeal_ruling,
            'filedAt' => $date($this->appeal_filed_at),
            'judgedAt' => $date($this->appeal_judged_at),
        ];
    }

    // الشكل الذي تتوقعه الواجهة (يطابق DATA.cases مع تفاصيل الجلسة والمستشار والمحكمة)
    public function toCard(): array
    {
        $nextLive = $this->nextHearingLive();
        $firstHearing = $this->relationLoaded('hearings') ? $this->hearings->first() : null;

        return [
            'no' => $this->number,
            'type' => $this->type,
            'status' => $this->status,
            'tone' => $this->tone,
            ...$this->stateFlags(),
            'update' => $this->update_text,
            'next' => $this->nextHearingLabel(),
            'fee' => $this->fee,
            'feeStatus' => $this->fee_status,
            // ما يُسدَّد فعلاً بزرّ «سداد» — الفاتورة نفسها التي يفتحها (`CaseFee::nextPayable`) شاملةً الضريبة؛
            // كان الزرّ يعرض الأتعاب قبل الضريبة (20,000) والفاتورة 23,000 (ثبت في المتصفّح 2026-09-30)
            'amountDue' => $this->fee_status === 'pending_payment' ? CaseFee::nextPayable($this)?->amount : null,
            'invoice' => $this->invoice_text,
            'department' => $this->department,
            'createdAt' => $this->created_at?->format('Y-m-d'),
            'assignedLawyer' => LawyerName::forClient($this->assigned_lawyer_id ? $this->assignedLawyer : null, $this->assigned_lawyer, 'المستشار المخصص'),
            'court' => $this->court ?: ($nextLive?->court ?: ($firstHearing?->court ?? 'المحكمة المختصة')),
            'najiz' => $this->najizCard(),
            'appeal' => $this->appealCard(),
            'nextHearing' => $nextLive ? [
                'id' => $nextLive->id,
                'title' => $nextLive->title,
                'court' => $nextLive->court,
                'day' => $nextLive->day,
                'startsAt' => $nextLive->starts_at?->format('Y-m-d H:i'),
                'label' => $nextLive->label(),
            ] : null,
            'hearingsCount' => $this->relationLoaded('hearings') ? $this->hearings->count() : $this->hearings()->count(),
            'documentsCount' => $this->relationLoaded('documents') ? $this->documents->count() : $this->documents()->count(),
            'installmentsTotal' => $this->installments_total,
            'installmentsPaid' => $this->installments_paid,
            'pleadingStatus' => $this->pleading_status,
            'ruling' => $this->ruling,
        ];
    }

    /**
     * **القضيّة النشطة** — كلّ ما لم يُغلق أو يُؤرشف، ومنها المعلّقة على الأتعاب والمحكومة
     * (قرار المالك 2026-09-27). وهو أوسع من `CaseJourney::ACTIVE` (مجموعة تبويبٍ للعرض). التعريف الواحد لعدّادات اللوحات والشارات وبطاقات العميل؛
     * كانت قوائم محلّيّة تختلف في «صدر الحكم» فيتباين الرقم بين شاشتين.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', CaseJourney::CLOSED);
    }

    public function isActive(): bool
    {
        return ! in_array($this->status, CaseJourney::CLOSED, true);
    }

    /**
     * **أعلام الحالة للواجهة — والبثّ يحملها أيضاً.** كانت أربع صفحات تنسخ `['مغلقة','مؤرشفة']`
     * و`['صدر الحكم','مغلقة']` لتقرّر: أتُفتح المحادثة؟ أتُحدَّث الجلسات؟ أيُصحَّح الحكم؟
     *
     * @return array{isActive: bool, postJudgment: bool}
     */
    public function stateFlags(): array
    {
        return [
            'isActive' => $this->isActive(),
            'postJudgment' => in_array($this->status, CaseJourney::POST_JUDGMENT, true),
            // الأرشيف للقراءة فقط — علمٌ من التعداد تقرؤه الواجهة بدل مقارنة نصّ الحالة «مؤرشفة»
            'isArchived' => $this->status === CaseStatus::Archived->value,
            // منظورةٌ أمام المحكمة — تُجدول فيها الجلسات ويُسجَّل الحكم
            'inCourt' => $this->status === CaseStatus::InCourt->value,
        ];
    }

    /**
     * **اللون يُحسب من الحالة عند القراءة (`CaseStatus::tone` عبر `CaseJourney::toneFor`)** — العمود المخزَّن يُكتب مع الانتقال
     * لكنّه لا يُقرأ: كانت حمولاتٌ ترسله خاماً وأخرى تحسبه، وصفوفٌ قديمة تحمل لوناً غير لون
     * حالتها، وشاشة التوزيع تسدّ فراغه بألوانٍ لا يُنتجها الخادم. فكلّ `->tone` الآن هو لون الحالة.
     */
    protected function tone(): Attribute
    {
        return Attribute::get(fn () => CaseJourney::toneFor((string) $this->status));
    }

    /** تصنيف القضيّة الآليّ — ومسودّة اللائحة نسخُها من رسائلها (`CaseMessage`). */
    public function revisionKinds(): array
    {
        return ['case_classification' => ['ai_classification']];
    }

    public function revisionOwner(): ?Model
    {
        return $this;
    }
}
