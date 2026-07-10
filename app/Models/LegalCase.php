<?php

namespace App\Models;

use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LegalCase extends Model
{
    use HasBranch;

    // Case كلمة محجوزة في PHP، لذا نستخدم LegalCase مع جدول cases
    protected $table = 'cases';

    protected $fillable = [
        'user_id', 'ticket_id', 'number', 'type', 'assigned_lawyer', 'assigned_lawyer_id', 'branch', 'department', 'status', 'tone',
        'update_text', 'next_hearing', 'invoice_text', 'paid_text', 'fee', 'fee_status',
        'lawyer_fee', 'lawyer_pct', 'pleading_status', 'ruling',
        'pay_plan', 'installments_total', 'installments_paid',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    // حساب المحامي المسند (المصدر الموثوق؛ العمود النصي للعرض فقط)
    public function assignedLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_lawyer_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CaseMessage::class, 'case_id')->orderBy('id');
    }

    public function hearings(): HasMany
    {
        return $this->hasMany(CaseHearing::class, 'case_id')->orderBy('id');
    }

    public function execution(): HasOne
    {
        return $this->hasOne(Execution::class, 'case_id');
    }

    // ربط المسار برقم القضية بدل المعرّف
    public function getRouteKeyName(): string
    {
        return 'number';
    }

    // الشكل الذي تتوقعه الواجهة (يطابق DATA.cases مع الجلسة القادمة)
    public function toCard(): array
    {
        return [
            'no' => $this->number,
            'type' => $this->type,
            'status' => $this->status,
            'tone' => $this->tone,
            'update' => $this->update_text,
            'next' => $this->next_hearing,
            'fee' => $this->fee,
            'feeStatus' => $this->fee_status,
            'invoice' => $this->invoice_text,
        ];
    }
}
