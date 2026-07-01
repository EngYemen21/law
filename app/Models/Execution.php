<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Execution extends Model
{
    protected $fillable = [
        'user_id', 'number', 'subject', 'status', 'tone', 'last_action',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ExecutionMessage::class)->orderBy('id');
    }

    // ربط المسار برقم الطلب بدل المعرّف
    public function getRouteKeyName(): string
    {
        return 'number';
    }

    // الشكل الذي تتوقعه الواجهة (يطابق DATA.execs)
    public function toCard(): array
    {
        return [
            'no' => $this->number,
            'subject' => $this->subject,
            'status' => $this->status,
            'tone' => $this->tone,
            'last' => $this->last_action,
        ];
    }
}
