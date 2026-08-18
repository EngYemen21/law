<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'user_id', 'case_id', 'consult_id', 'exec_id', 'number', 'description', 'amount', 'status', 'tone', 'due_label', 'due_at', 'paid',
        'gateway_ref', 'gateway_payment_id', 'proof_path', 'proof_uploaded_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'paid' => 'boolean',
        'due_at' => 'date',
        'proof_uploaded_at' => 'datetime',
    ];

    /** تجاوزت استحقاقها دون سداد — يوم الاستحقاق نفسه ليس تأخّراً (حتى نهايته) */
    public function isOverdue(): bool
    {
        return ! $this->paid && $this->due_at !== null && $this->due_at->copy()->endOfDay()->isPast();
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
            // تاريخ استحقاق حقيقي إن وُجد — النص القديم («خلال 3 أيام» الأبدية) احتياط للسجلات القديمة
            'due' => $this->due_at
                ? ($this->isOverdue() ? 'استُحقّت في ' : 'تستحق قبل ').$this->due_at->locale('ar')->translatedFormat('d F Y')
                : $this->due_label,
            'overdue' => $this->isOverdue(),
            'paid' => $this->paid,
            'hasProof' => $this->proof_path !== null,   // رُفع إثبات تحويل بانتظار المراجعة
        ];
    }
}
