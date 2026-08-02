<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دفتر مدفوعات البوّابة (ledger): صفّ لكلّ حدث دفع يصل من ميسّر — تدقيق ومطابقة محاسبيّة.
 * يُسجَّل تلقائيّاً من App\Support\PaymentReconciler::settle (idempotent عبر gateway_payment_id).
 */
class Payment extends Model
{
    protected $fillable = [
        'invoice_id', 'gateway', 'gateway_invoice_id', 'gateway_payment_id',
        'status', 'amount', 'currency', 'source_channel', 'raw', 'reconciled_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'raw' => 'array',
        'reconciled_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
