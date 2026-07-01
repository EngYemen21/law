<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'user_id', 'case_id', 'number', 'description', 'amount', 'status', 'tone', 'due_label', 'paid',
    ];

    protected $casts = [
        'amount' => 'integer',
        'paid' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
        ];
    }
}
