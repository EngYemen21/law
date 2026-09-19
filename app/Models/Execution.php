<?php

namespace App\Models;

use App\Domain\Journey\GuardsJourneyState;
use App\Enums\Role;
use App\Models\Concerns\ClipsPreviewText;
use App\Models\Concerns\PurgesDocumentFiles;
use App\Support\ConversationFiles;
use App\Support\CorrFlow;
use App\Support\ExecFlow;
use App\Support\LawyerName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Execution extends Model
{
    use ClipsPreviewText, GuardsJourneyState, PurgesDocumentFiles;

    /**
     * **حالتا الإنهاء على الصفوف القديمة** (stage=null، من البذور وتحويل قضية→تنفيذ).
     * «مغلق» يكتبها `ExecService::close`، و«مكتمل» **لا يكتبها شيء اليوم** — تبقى لحماية
     * صفوف الإنتاج السابقة وحدها. مصدرٌ واحد: كانت القائمة مكرّرةً حرفياً في `isClosed`
     * ولوحتَي الإدارة والموظّف و`LawyerAvailability`، فتتباعد نسخُها عند أوّل تعديل.
     */
    public const CLOSED_STATUSES = ['مكتمل', 'مغلق'];

    /** سطر المعاينة في بطاقات القوائم — varchar(255) يستقبل نصّ المستخدم بلا سقف. */
    protected array $previewText = ['last_action'];

    protected $fillable = [
        'user_id', 'case_id', 'ticket_id', 'number', 'subject', 'assigned_lawyer', 'assigned_lawyer_id', 'court', 'status', 'tone', 'last_action',
        // تدفّق التنفيذ التجاريّ (10 مراحل)
        'stage', 'sanad', 'defendant', 'amount', 'notes', 'docs', 'client_code',
        'ai_done', 'ai_source', 'ai_summary', 'ai_missing', 'ai_procedures', 'ai_study', 'ai_approved_at', 'ai_approved_by',
        'decision', 'fee', 'vat', 'duration', 'pay_method', 'fee_approved', 'offer_status',
        // نماذج الأتعاب: نموذج المكتب (ثابت/نسبة) وخطّة العميل (كامل/تقسيط)
        'fee_mode', 'collection_fee_pct', 'pay_plan', 'installments_total', 'installments_paid',
        'invoice_no', 'paid', 'paid_at', 'exec_no', 'payment_reminder_sent_at',
        // مسار ناجز داخل المرحلتين 7 و8 (قرار المالك 2026-09-12) وسبب الإنهاء
        'najiz_request_no', 'najiz_filed_at', 'circuit', 'registered_at', 'notified_at', 'pay_due_at',
        'measures', 'collected', 'closed_reason', 'pay_due_alert_sent_at',
        // إعادة جدولة الدراسة عند تعذّر الذكاء — لا قالب يملأ الفراغ
        'ai_attempts', 'ai_attempted_at',
    ];

    protected $casts = [
        'stage' => 'integer',
        'amount' => 'integer',
        'fee' => 'integer',
        'vat' => 'integer',
        'docs' => 'array',
        'ai_done' => 'boolean',
        'ai_missing' => 'array',
        'ai_approved_at' => 'datetime',
        'ai_procedures' => 'array',
        'ai_study' => 'array',
        'fee_approved' => 'boolean',
        'collection_fee_pct' => 'decimal:2',
        'installments_total' => 'integer',
        'installments_paid' => 'integer',
        'paid' => 'boolean',
        'paid_at' => 'datetime',
        'payment_reminder_sent_at' => 'datetime',
        'najiz_filed_at' => 'date',
        'registered_at' => 'date',
        'notified_at' => 'date',
        'pay_due_at' => 'date',
        'pay_due_alert_sent_at' => 'datetime',
        'ai_attempts' => 'integer',
        'ai_attempted_at' => 'datetime',
        'measures' => 'array',
        'collected' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    // حساب محامي التنفيذ المسند (المصدر الموثوق؛ العمود النصي للعرض فقط)
    public function assignedLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_lawyer_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ExecutionMessage::class)->orderBy('id');
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(ExecutionProcedure::class)->orderBy('id');
    }

    // مستندات مطلوبة من العميل على الطلب (يطلبها القسم، يرفعها العميل)
    public function documents(): HasMany
    {
        // الأحدث أوّلاً — بخلاف الإجراءات (خطّ زمنيّ) ودفعات التقسيط (بترتيب الخطّة)
        return $this->hasMany(ExecutionDocument::class)->orderByDesc('id');
    }

    // المخاطبات الرسميّة المرتبطة بملفّ التنفيذ
    public function correspondences(): HasMany
    {
        return $this->hasMany(Correspondence::class)->latest('id');
    }

    // فواتير أتعاب التنفيذ (المفتاح exec_id) — لدفع العرض عبر ميسّر
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'exec_id');
    }

    // ربط المسار برقم الطلب بدل المعرّف
    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /** هل اعتمد إنسانٌ مفوَّض تحليلَ هذا الطلب؟ (نظير `Consult::summaryApproved`) */
    public function aiApproved(): bool
    {
        return $this->ai_approved_at !== null;
    }

    /**
     * نموذج الأتعاب الذي يقرؤه المحرّك — `fixed` للصفوف السابقة للعمود، وهو ما كانت
     * تفعله فعلاً (فاتورةٌ واحدة بكامل الأتعاب) فالافتراضُ يصف الماضي لا يفترضه.
     */
    public function feeMode(): string
    {
        return $this->fee_mode === 'percent' ? 'percent' : 'fixed';
    }

    /** خطّة سداد العميل في النموذج الثابت — `full` ما لم يختر التقسيط. */
    public function payPlan(): string
    {
        return $this->pay_plan === 'install' ? 'install' : 'full';
    }

    /**
     * **هل اكتمل ما على العميل من أتعاب؟** غير `paid`: تلك تعني «انفتح الملفّ» — وفي
     * التقسيط تصير صادقةً بأوّل دفعة وعلى الملفّ دفعتان. وفي النموذج النسبيّ لا مبلغ
     * مستحقٌّ أصلاً حتى يقع تحصيل، ففواتيره تُقاس واحدةً واحدة لا كخطّة.
     */
    public function feeFullySettled(): bool
    {
        if ($this->feeMode() === 'fixed' && $this->payPlan() === 'install') {
            return (int) $this->installments_paid >= max(1, (int) $this->installments_total);
        }

        return (bool) $this->paid;
    }

    /**
     * شكل سجلّ تدفّق التنفيذ التجاريّ للواجهة (يطابق نوع ExecReq في exec-flow.ts).
     * $masked: إخفاء اسم العميل — لا تمرّره اليوم أيُّ شاشة (قرار المالك 2026-09-11: لا تقنيع على الطاقم).
     * $internal: عرض الملاحظات الداخليّة (who=note) — للمكتب وحده. كانت مُرشَّحة عن
     * الجميع بلا استثناء، فلم يكن للمكتب قناة يرى فيها ما لا يُعرَض للعميل.
     */
    public function toFlowCard(bool $masked = false, bool $internal = false): array
    {
        $client = $this->user?->name ?? '—';
        $stage = $this->effectiveStage();
        $showAi = $internal || $this->aiApproved();

        return [
            'id' => $this->number,
            'client' => $masked ? self::maskName($client) : $client,
            'code' => $this->client_code ?? '',
            'sanad' => $this->sanad ?? '',
            'subject' => $this->subject,
            'defendant' => $this->defendant ?? '',
            'amount' => (int) $this->amount,
            'notes' => $this->notes ?? '',
            'docs' => $this->docs ?? [],
            'stage' => $stage,
            'channel' => 'exec.'.$this->id,
            'messages' => $this->relationLoaded('messages')
                ? $this->flowMessages($internal)
                : [],
            'docItems' => $this->relationLoaded('documents')
                ? $this->documents->map->toData()->all()
                : [],
            'lawyer' => $internal ? ($this->assigned_lawyer ?? '') : LawyerName::forClient($this->assigned_lawyer_id ? $this->assignedLawyer : null, $this->assigned_lawyer, ''),
            'aiDone' => (bool) $this->ai_done,
            // مصدر المخرج للواجهة: '' = غير معروف (صفوف ما قبل الهجرة). الواجهة لا تعرض
            // عنوان «الملخّص الذكيّ» إلا لتحليل فعليّ — الاحتياطيّ يظهر بعنوانه الصادق.
            'aiSource' => $this->ai_source ?? '',
            // **لا يصل العميل تحليلٌ لم يعتمده محامٍ.** و`ai_success` تعني أن النموذج
            // أعاد JSON صالحاً لا أكثر — بلا استرجاع ولا مطابقة سند ولا مرورِ إنسان،
            // بينما `ExecService::applyAnalysis` يسجّل القيد «يتطلّب مراجعة» صراحةً.
            // والحجب هنا لا في الواجهة: حجبٌ واجهيّ يُبقي النصّ في حمولة المتصفّح.
            // و`$internal` هو فاصل المكتب/العميل القائم في هذه الدالّة أصلاً.
            'aiSummary' => $showAi ? ($this->ai_summary ?? '') : '',
            'aiMissing' => $showAi ? ($this->ai_missing ?? []) : [],
            'aiProcedures' => $showAi ? ($this->ai_procedures ?? []) : [],
            'aiApproved' => $this->aiApproved(),
            'aiPending' => filled($this->ai_summary) && ! $this->aiApproved(),
            // **الدراسة الجاهزة للتسعير** — الحقول الثلاثة أعلاه تبقى كما هي لمن يقرؤها،
            // وهذه تجمعها مع مدخلات التسعير الستّة في كائنٍ واحد. الحجب هو الحجب نفسه.
            'study' => $this->studyCard($showAi),
            // معرّف المحامي المسنَد — **للمكتب وحده**: العميل يرى الاسم مقنّعاً
            // (`LawyerName::forClient`)، فتمريرُ المعرّف إليه ينقض التقنيع بجدول واحد.
            'lawyerId' => $internal ? $this->assigned_lawyer_id : null,
            'canAssign' => self::viewerCanAssign($this),
            'decision' => $this->decision ?? '',
            'fee' => (int) $this->fee,
            'vat' => (int) $this->vat,
            // نسبة الضريبة من الإعدادات — الواجهة كانت تحسبها 15% ثابتة فتخالف الفاتورة إن غُيّرت
            'vatRate' => Setting::vatRate(),
            'duration' => $this->duration ?? '',
            // مشتقٌّ من النموذج والخطّة لا نصّاً حرّاً — الشاشة لا تستطيع أن تَعِد بما لا يقع
            'payMethod' => $this->pay_method ?? '',
            'feeMode' => $this->feeMode(),
            // `decimal:2` يعود نصّاً من Eloquent؛ والعقد يقول `number` فيُصرَّح بالتحويل
            'collectionFeePct' => (float) $this->collection_fee_pct,
            'payPlan' => $this->pay_plan ?? '',
            'installmentsTotal' => (int) $this->installments_total,
            'installmentsPaid' => (int) $this->installments_paid,
            // فواتير الملفّ — دفعات الخطّة وفواتير الأتعاب عن التحصيل، تُشحن متى حُمّلت العلاقة
            'invoices' => $this->relationLoaded('invoices')
                ? $this->invoices->sortBy('id')->map->toCard()->values()->all()
                : [],
            'feeApproved' => (bool) $this->fee_approved,
            'offerStatus' => $this->offer_status ?? '',
            'invoiceNo' => $this->invoice_no ?? '',
            'paid' => (bool) $this->paid,
            'execNo' => $this->exec_no ?? '',
            'procedures' => $this->procedures->map(fn (ExecutionProcedure $p) => [
                'a' => $p->title,
                't' => $p->created_at?->format('Y/m/d h:i') ?? '',
                'type' => $p->type,
                'status' => $p->status,
            ])->values()->all(),
            'najiz' => $this->najizCard(),
            'closed' => $this->isClosed(),
            'linkedCorr' => $this->relationLoaded('correspondences')
                ? $this->correspondences->map(fn (Correspondence $c) => [
                    'id' => $c->number,
                    'entity' => $c->entity,
                    'stageLabel' => CorrFlow::label((int) $c->stage).($c->reply_body ? ' · ورد الرد' : ''),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * **بطاقة الدراسة قبل التسعير** — `null` حين لا دراسة بعد، وحين تُحجب عن العميل.
     *
     * الحجب يتبع `aiSummary` حرفياً (`$showAi`): دراسةٌ لم يعتمدها محامٍ لا تصل صاحب
     * الطلب — ومدخلات التسعير أخطر من الملخّص، ففيها «إعسار محتمل» و«منازعة تنفيذيّة»
     * و«معقّد»، وهي أحكامٌ يقرؤها العميل توقّعاً لمصير ملفّه. و`null` لا كائنٌ فارغ:
     * الواجهة تخفي القسم كلّه بدل عرض بطاقةٍ بحقولٍ خاوية.
     *
     * والصفوف السابقة لعمود `ai_study` تُقرأ بقيمٍ فارغة معلنة — ملخّصها ونواقصها
     * وإجراءاتها تظهر كما كانت، ومدخلات التسعير خاوية بصدق.
     *
     * @return array{summary:string,missing:array<int,string>,procedures:array<int,string>,readiness:string,difficulty:string,expectedProceduresCount:int,durationEstimate:string,recovery:array<int,string>,risks:array<int,string>,pending:bool,approved:bool}|null
     */
    private function studyCard(bool $showAi): ?array
    {
        if (! $showAi || blank($this->ai_summary)) {
            return null;
        }

        $study = is_array($this->ai_study) ? $this->ai_study : [];

        return [
            'summary' => (string) $this->ai_summary,
            'missing' => array_values($this->ai_missing ?? []),
            'procedures' => array_values($this->ai_procedures ?? []),
            'readiness' => (string) ($study['readiness'] ?? ''),
            'difficulty' => (string) ($study['difficulty'] ?? ''),
            'expectedProceduresCount' => (int) ($study['expected_procedures_count'] ?? 0),
            'durationEstimate' => (string) ($study['duration_estimate'] ?? ''),
            'recovery' => array_values((array) ($study['recovery_indicators'] ?? [])),
            'risks' => array_values((array) ($study['risks'] ?? [])),
            'pending' => ! $this->aiApproved(),
            'approved' => $this->aiApproved(),
        ];
    }

    /**
     * **هل يملك القارئ الحاليّ إسناد هذا الملفّ الآن؟** (قرار المالك: الإدارة تُوجّه،
     * والموظّف المخوَّل يُوجّه بصلاحيّة «إجراءات المحكمة والجلسات» نفسها التي مُنحت
     * لخطوات ناجز.)
     *
     * الشرط يطابق حارس `ExecFlowController::act` حرفاً بحرف — لا الدورَ وحده: زرٌّ يظهر
     * ثمّ يردّه الخادم بـ403 أسوأ من زرٍّ لا يظهر. فإعادةُ الإسناد للإدارة وحدها (الموظّف
     * يُسند غير المسنَد لا ينزع ملفّاً من محامٍ)، والملفّ المنتهي لا يُسنَد.
     */
    private static function viewerCanAssign(self $exec): bool
    {
        $viewer = auth()->user();

        if ($viewer === null || $exec->isClosed()) {
            return false;
        }

        return match ($viewer->role) {
            Role::Admin => true,
            Role::Employee => $exec->assigned_lawyer_id === null && $viewer->can('إجراءات المحكمة والجلسات'),
            default => false,
        };
    }

    /**
     * المرحلة الفعّالة: التنفيذات القديمة (stage=null، من البذور/تحويل قضية→تنفيذ) تُعامَل
     * كملفّات مفتوحة أصلاً — «مغلق»(9) إن اكتملت، وإلا «قيد التنفيذ»(8). العمود يبقى null في القاعدة
     * كي لا تتأثّر استعلامات بقيّة الأدوار (whereNull/whereNotNull). مصدر وحيد يستخدمه العرض
     * (toFlowCard) وحارس الانتقالات (ExecService::guard) معاً — لا تكرار.
     */
    public function effectiveStage(): int
    {
        if ($this->stage !== null) {
            return (int) $this->stage;
        }

        // «مغلق» فقط = مؤرشف (9)؛ «مكتمل» وغيرها = قيد التنفيذ (8) فتبقى قابلة للإغلاق من الإدارة
        return $this->status === 'مغلق' ? 9 : 8;
    }

    /**
     * **مسار ناجز داخل ملفّ التنفيذ** (المرحلتان 7 و8) — نظير `LegalCase::najizCard()`.
     * تُعرض البطاقة بعد فتح الملفّ، أو متى وُجد رقم طلبٍ (الصفوف القديمة المفتوحة من قضايا).
     */
    public function najizCard(): ?array
    {
        if ($this->effectiveStage() < 7 && blank($this->najiz_request_no)) {
            return null;
        }

        $due = $this->pay_due_at;

        return [
            'requestNo' => (string) ($this->najiz_request_no ?? ''),
            'filedAt' => $this->najiz_filed_at?->locale('ar')->translatedFormat('j F Y') ?? '',
            'court' => (string) ($this->court ?? ''),
            'circuit' => (string) ($this->circuit ?? ''),
            'registeredAt' => $this->registered_at?->locale('ar')->translatedFormat('j F Y') ?? '',
            'notifiedAt' => $this->notified_at?->locale('ar')->translatedFormat('j F Y') ?? '',
            'payDueAt' => $due?->locale('ar')->translatedFormat('j F Y') ?? '',
            // انقضاء المهلة من الخادم لا من ساعة المتصفّح — عليه يُبنى التنبيه وطلب إجراءات عدم الوفاء
            'payDueOver' => $due !== null && $due->isPast(),
            'measures' => array_values($this->measures ?? []),
            'collected' => (int) $this->collected,
            'amount' => (int) $this->amount,
            'closedReason' => (string) ($this->closed_reason ?? ''),
            // القوائم المسموحة من الخادم لا منسوخةً في الواجهة: التحقّق في `ExecFlowController::act`
            // يقيسها على `ExecFlow`، فنسخةٌ يدويّة في TS تتباعد عنها وتعرض خياراً يردّه الخادم.
            'measureOptions' => ExecFlow::MEASURES,
            'closeReasons' => ExecFlow::CLOSE_REASONS,
        ];
    }

    /**
     * **ملفٌّ منتهٍ: للقراءة فقط.** مصدرٌ واحد للشاشة وللخادم — كانت الشاشة تقرأ `stage >= 9`
     * وحدها، والخادم يرفض «مكتمل» أيضاً: فصفٌّ قديم حالته «مكتمل» (بلا `stage`) يظهر مفتوحاً
     * ومحادثته قابلة للكتابة، ثمّ يردّ الخادم 422 عند أوّل رسالة.
     */
    public function isClosed(): bool
    {
        return $this->effectiveStage() >= 9 || in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /** رسائل المحادثة للبطاقة: المرفقات روابط تنزيل، واسم المحامي للعميل «الاسم. الحرف». */
    private function flowMessages(bool $internal): array
    {
        $messages = ConversationFiles::linkLegacyChips(
            ($internal ? $this->messages : $this->messages->where('who', '!=', 'note'))
                ->values()->map->toMessage()->all(),
            'exec',
            $this->relationLoaded('documents') ? $this->documents : []
        );

        return $internal ? $messages : LawyerName::inMessages($messages);
    }

    private static function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) <= 1) {
            return $name;
        }

        return $parts[0].' '.implode(' ', array_map(fn ($p) => mb_substr($p, 0, 1).'…', array_slice($parts, 1)));
    }
}
