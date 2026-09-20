<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دفتر مدفوعات البوّابة (ledger): صفّ لكلّ حدث دفع يصل من ميسّر — تدقيق ومطابقة محاسبيّة.
 * يُسجَّل تلقائيّاً من App\Support\PaymentReconciler::settle (idempotent عبر gateway_payment_id).
 *
 * **الوحدة:** `amount` مختلط — بالهللة في صفوف البوّابة وبالريال في التحصيل اليدويّ، فلا يُجمع.
 * `amount_halalas` **بالهللة دائماً** وهو وحده القابل للجمع والمطابقة.
 */
class Payment extends Model
{
    protected $fillable = [
        'invoice_id', 'gateway', 'gateway_invoice_id', 'gateway_payment_id',
        'status', 'amount', 'amount_halalas', 'currency', 'source_channel', 'raw', 'reconciled_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'amount_halalas' => 'integer',
        'raw' => 'array',
        'reconciled_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
