<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'user_id', 'case_id', 'consult_id', 'exec_id', 'number', 'description', 'amount', 'status', 'tone', 'due_label', 'paid',
        'gateway_ref', 'gateway_payment_id', 'proof_path', 'proof_uploaded_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'paid' => 'boolean',
        'proof_uploaded_at' => 'datetime',
    ];

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
        return [
            'no' => $this->number,
            'desc' => $this->description,
            'amount' => $this->amount,
            'status' => $this->status,
            'tone' => $this->tone,
            'due' => $this->due_label,
            'paid' => $this->paid,
            'hasProof' => $this->proof_path !== null,   // رُفع إثبات تحويل بانتظار المراجعة
        ];
    }
}
