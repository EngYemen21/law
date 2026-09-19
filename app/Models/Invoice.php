<?php

namespace App\Models;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\GuardsJourneyState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use GuardsJourneyState;

    protected $fillable = [
        'user_id', 'case_id', 'consult_id', 'exec_id', 'installment_no', 'number', 'description', 'amount', 'status', 'tone', 'due_label', 'due_at', 'reminder_sent_at', 'paid',
        'gateway_ref', 'gateway_payment_id', 'proof_path', 'proof_uploaded_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'installment_no' => 'integer',
        'paid' => 'boolean',
        'due_at' => 'date',
        'reminder_sent_at' => 'datetime',
        'proof_uploaded_at' => 'datetime',
    ];

    /**
     * تجاوزت استحقاقها دون سداد — يوم الاستحقاق نفسه ليس تأخّراً (حتى نهايته).
     * والملغاة لا تتأخّر: لا يُطالَب بها أحد، وكانت تُعرض «متأخرة» حمراء بعد استحقاقها
     * فيُخفى إلغاؤها عن العميل ويُحسب مبلغها ديناً في المحاسبة.
     */
    public function isOverdue(): bool
    {
        return ! $this->paid && ! $this->isCancelled() && $this->due_at !== null && $this->due_at->copy()->endOfDay()->isPast();
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function consult(): BelongsTo
    {
        return $this->belongsTo(Consult::class);
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
            'hasProof' => $this->proof_path !== null,   // رُفع إثبات تحويل بانتظار المراجعة
            'installmentNo' => $this->installment_no,   // موضعها من خطّة التقسيط — null لغيرها
        ];
    }
}
