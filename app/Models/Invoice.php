<?php

namespace App\Models;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\GuardsJourneyState;
use App\Support\Finance\InstallmentPlan;
use App\Support\Finance\InvoiceFactory;
use App\Support\SettingsRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use GuardsJourneyState;

    protected $fillable = [
        'user_id', 'case_id', 'consult_id', 'exec_id', 'share_user_id', 'installment_no', 'number', 'description', 'amount', 'status', 'tone', 'due_label', 'due_at', 'reminder_sent_at', 'paid',
        'gateway', 'gateway_ref', 'gateway_payment_id', 'proof_path', 'proof_uploaded_at',
        // م١: الضريبة ومحطّات دورة الحياة — `vat_rate` مجمَّدةٌ يوم الإصدار (`Finance\InvoiceFactory`)
        'paid_at', 'subtotal', 'vat_rate', 'vat_amount', 'seller_name', 'seller_vat_number',
        'issued_at', 'cancelled_at', 'written_off_at', 'written_off_reason',
    ];

    protected $casts = [
        'amount' => 'integer',
        'installment_no' => 'integer',
        'paid' => 'boolean',
        'due_at' => 'date',
        'reminder_sent_at' => 'datetime',
        'proof_uploaded_at' => 'datetime',
        'subtotal' => 'integer',
        'vat_rate' => 'integer',
        'vat_amount' => 'integer',
        'paid_at' => 'datetime',
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'written_off_at' => 'datetime',
    ];

    /**
     * تجاوزت استحقاقها دون سداد — يوم الاستحقاق نفسه ليس تأخّراً (حتى نهايته).
     * والملغاة لا تتأخّر: لا يُطالَب بها أحد، وكانت تُعرض «متأخرة» حمراء بعد استحقاقها
     * فيُخفى إلغاؤها عن العميل ويُحسب مبلغها ديناً في المحاسبة.
     */
    public function isOverdue(): bool
    {
        return $this->isOutstanding() && $this->due_at !== null && $this->due_at->copy()->endOfDay()->isPast();
    }

    /** الحالتان اللتان تُسقطان المطالبة: لا تُسدَّدان ولا تُعدّان ديناً ولا تتأخّران. */
    private const SETTLED_WITHOUT_PAYMENT = [InvoiceStatus::Cancelled, InvoiceStatus::WrittenOff];

    /**
     * **ذمّةٌ قائمة** — غير مدفوعة، وليست ملغاة ولا معدومة. التعريف الواحد الذي تقرؤه الأتعاب
     * والإيرادات والمالية ولوحة الإدارة؛ كان مكتوباً يدويّاً في سبعة مواضع.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('paid', false)
            ->whereNotIn('status', array_map(fn (InvoiceStatus $s) => $s->value, self::SETTLED_WITHOUT_PAYMENT));
    }

    /** `scopeOutstanding` مطبَّقاً على صفٍّ واحد. */
    public function isOutstanding(): bool
    {
        return ! $this->paid && ! in_array(InvoiceStatus::tryFrom((string) $this->status), self::SETTLED_WITHOUT_PAYMENT, true);
    }

    /**
     * **ما يطالَب به العميل** — ذمّةٌ قائمة صدرت إليه؛ المسوّدة لم تُصدَر بعد فلا تُعدّ عليه.
     * تقرؤه شارة `/invoices` ولوحة العميل وعدّاد صفحة فواتيره — فلا تختلف الثلاثة.
     */
    public function scopeOwedByClient(Builder $query): Builder
    {
        return $query->outstanding()->where('status', '!=', InvoiceStatus::Draft->value);
    }

    public function isOwedByClient(): bool
    {
        return $this->isOutstanding() && $this->status !== InvoiceStatus::Draft->value;
    }

    /**
     * **الصادر (المُفوتَر)** — كلّ فاتورةٍ إلّا الملغاة: الملغاة لم تُطالِب أحداً، والمعدومة داخله
     * (صدرت فعلاً ثمّ أُسقطت — وإخراجها يعيد كتابة الماضي). مقامُ نسبة التحصيل في كلّ الشاشات.
     */
    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', '!=', InvoiceStatus::Cancelled->value);
    }

    /**
     * **المحصَّل** — ما سدّده العميل فعلاً. مصدر نصيب المحامي (`Finance\StaffEarnings`): يُستحقّ
     * بقدر ما سُدّد، والمبلغ المعتمد فيه `taxBreakdown()['subtotal']` (قبل الضريبة).
     */
    public function scopeCollected(Builder $query): Builder
    {
        return $query->where('paid', true);
    }

    /** `isOverdue()` بلغة SQL — يوم الاستحقاق نفسه ليس تأخّراً. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->outstanding()->whereNotNull('due_at')->whereDate('due_at', '<', today());
    }

    /**
     * **بيانات البائع تُجمَّد لحظة الإصدار** (`issued_at`) — في كلّ مسارٍ يُصدر فاتورة (المصنع، واعتماد المسوّدة). كانت
     * الفاتورة الضريبيّة ورمز ZATCA يقرآن اسم المكتب ورقمه الضريبيّ لحظة العرض، فيغيّر تعديلُهما فواتيرَ صدرت.
     */
    protected static function booted(): void
    {
        static::saving(function (Invoice $invoice): void {
            if ($invoice->issued_at !== null && $invoice->seller_name === null) {
                $invoice->seller_name = SettingsRegistry::str('office_name');
                $invoice->seller_vat_number = SettingsRegistry::str('office_vat_number');
            }
        });
    }

    /** اسم البائع كما صدرت به — والصفّ القديم غير المجمَّد يأخذ الحاليّ. */
    public function sellerName(): string
    {
        return $this->seller_name ?? SettingsRegistry::str('office_name');
    }

    /** الرقم الضريبيّ كما صدرت به ('' = صدرت والمكتب غير مسجَّل) — والقديم غير المجمَّد يأخذ الحاليّ. */
    public function sellerVatNumber(): string
    {
        return $this->seller_vat_number ?? SettingsRegistry::str('office_vat_number');
    }

    /**
     * **قسطٌ قبله قسطٌ مستحقّ** — لا يسدّده العميل قبل سابقه (تدقيق الدفع C): كان سدادُ الثالثة أوّلاً يفعّل
     * القضيّة والأولى غير مدفوعة. ولا يخصّ غيرَ دفعات الخطط، ولا المدفوعة ولا الملغاة.
     */
    public function awaitsEarlierInstallment(): bool
    {
        $owner = match (true) {
            $this->case_id !== null => 'case_id',
            $this->exec_id !== null => 'exec_id',
            default => null,
        };
        if ($this->installment_no === null || $owner === null || ! $this->isOutstanding()) {
            return false;
        }

        $next = InstallmentPlan::next($owner, (int) $this->getAttribute($owner));

        return $next !== null && $next->id !== $this->id;
    }

    /** أُلغيت (إعادة تسعير أو إلغاء طلب) — لا تُسدَّد ولا يُرفع لها إثبات ولا تُحصَّل. */
    public function isCancelled(): bool
    {
        return $this->status === InvoiceStatus::Cancelled->value;
    }

    /**
     * [الحالة، النغمة] الحيّتان — «متأخرة» تُشتق من الاستحقاق عند القراءة ولا تُخزَّن
     * (المخزّنة «مستحقة» الصفراء كانت تبقى كذلك بعد شهور من التجاوز).
     *
     * @return array{0:string,1:string}
     */
    public function liveStatus(): array
    {
        return $this->isOverdue() ? ['متأخرة', 'b-red'] : [(string) $this->status, (string) $this->tone];
    }

    /**
     * **تفصيل المال كما يُطبع على المستند الضريبيّ** — الأساس والنسبة والضريبة والإجماليّ.
     *
     * المصدر هو أعمدة الصفّ نفسه: النسبة **مجمَّدةٌ يوم الإصدار** فلا تتبع الإعداد، وهذا
     * بالضبط ما يجعل فاتورةً سلّمها المكتب قبل سنةٍ تُطبع اليوم بأرقامها هي.
     *
     * **والاحتياط لصفوفٍ بلا أعمدة**: الأعمدة أُضيفت في م١ ومُلئت رجعيّاً، لكنّ صفّاً يُنشأ
     * خارج `Finance\InvoiceFactory` (بذرةٌ أو تهيئة اختبار) قد يصل بلا ضريبة. وعكسُ الحساب
     * أصدق من طباعة صفرٍ يوهم بأنّ الفاتورة بلا ضريبة — وهو عينُ ما فعلته المهاجرة.
     *
     * @return array{amount:int, subtotal:int, vat_rate:int, vat_amount:int}
     */
    public function taxBreakdown(): array
    {
        if ($this->subtotal === null || $this->vat_amount === null) {
            return InvoiceFactory::taxFromTotal((int) $this->amount);
        }

        return [
            'amount' => (int) $this->amount,
            'subtotal' => (int) $this->subtotal,
            'vat_rate' => (int) ($this->vat_rate ?? 0),
            'vat_amount' => (int) $this->vat_amount,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function consult(): BelongsTo
    {
        return $this->belongsTo(Consult::class);
    }

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class, 'exec_id');
    }

    /** دفتر مدفوعات البوّابة لهذه الفاتورة (سجلّ تدقيق لكلّ حدث دفع). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ربط المسار برقم الفاتورة بدل المعرّف
    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /**
     * **تاريخ الاستحقاق كما يُقرأ** — المصدر الواحد للبطاقة والفاتورة المطبوعة.
     *
     * `due_label` نصٌّ نسبيّ مجمَّد لحظة الإصدار («خلال 3 أيام»)، فيبقى كذلك بعد أسابيع: كانت
     * الفاتورة المطبوعة تطبعه كما هو تحت «تاريخ الاستحقاق». التاريخ الحقيقيّ `due_at` أوّلاً،
     * والنصّ احتياطٌ للسجلّات القديمة التي لا تاريخ لها.
     */
    public function dueDateText(): ?string
    {
        return $this->due_at !== null
            ? $this->due_at->locale('ar')->translatedFormat('d F Y')
            : ($this->due_label ?: null);
    }

    // الشكل الذي تتوقعه الواجهة (يطابق DATA.invoices)
    public function toCard(): array
    {
        [$status, $tone] = $this->liveStatus();

        return [
            'no' => $this->number,
            'desc' => $this->description,
            'amount' => $this->amount,
            'status' => $status,
            'tone' => $tone,
            'due' => $this->due_at
                ? ($this->isOverdue() ? 'استُحقّت في ' : 'تستحق قبل ').$this->dueDateText()
                : $this->dueDateText(),
            'overdue' => $this->isOverdue(),
            'paid' => $this->paid,
            // الشاشة تُخفي الدفع ورفع الإثبات عن الملغاة — والخادم يرفضهما (`SubmitPaymentProof`)
            'cancelled' => $this->isCancelled(),
            // **أيُطالَب بها العميل؟** `paid` وحده لا يكفي: الملغاة والمعدومة غير مدفوعتين وليستا
            // ديناً، والمسوّدة لم تُصدَر. التعريف نفسه الذي تعدّ به الشارة ولوحة العميل.
            'receivable' => $this->isOwedByClient(),
            'hasProof' => $this->proof_path !== null,   // رُفع إثبات تحويل بانتظار المراجعة
            'installmentNo' => $this->installment_no,   // موضعها من خطّة التقسيط — null لغيرها
            // قسطٌ قبله مستحقّ: الشاشة تُخفي سداده، والخادم يرفضه (`InvoiceController::checkout`)
            'awaitsEarlier' => $this->awaitsEarlierInstallment(),
        ];
    }

    /**
     * **اللون يُحسب من الحالة عند القراءة (`InvoiceStatus::tone`)** — العمود المخزَّن يُكتب مع الانتقال
     * لكنّه لا يُقرأ: كانت حمولاتٌ ترسله خاماً وأخرى تحسبه، وصفوفٌ قديمة تحمل لوناً غير لون
     * حالتها، وشاشة التوزيع تسدّ فراغه بألوانٍ لا يُنتجها الخادم. فكلّ `->tone` الآن هو لون الحالة.
     */
    protected function tone(): Attribute
    {
        return Attribute::get(fn ($value) => InvoiceStatus::tryFrom((string) $this->status)?->tone() ?? (string) $value);
    }
}
