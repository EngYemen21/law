<?php

namespace App\Models;

use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Execution extends Model
{
    use HasBranch;

    protected $fillable = [
        'user_id', 'case_id', 'number', 'subject', 'assigned_lawyer', 'assigned_lawyer_id', 'branch', 'court', 'status', 'tone', 'last_action',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
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
            'lawyer' => $this->assigned_lawyer,
            'court' => $this->court,
        ];
    }
}
