<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    protected $fillable = [
        'user_id', 'ticket_id', 'ext_id', 'type', 'ico', 'lawyer', 'lawyer_id', 'day', 'time',
        'starts_at', 'duration_min', 'branch', 'status', 'tone', 'when_kind',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    // المحامي المسند بالمعرّف (مصدر الحقيقة لحساب التعارض والتفرّغ)
    public function lawyerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lawyer_id');
    }

    // الاستشارة المرتبطة بالموعد (مصدر حالة السداد الحقيقية لبطاقة الموعد)
    public function consult(): HasOne
    {
        return $this->hasOne(Consult::class, 'appointment_id');
    }

    // الشكل الذي تتوقعه الواجهة (يطابق DATA.appts)
    public function toCard(): array
    {
        return [
            'id' => $this->ext_id,
            'type' => $this->type,
            'ico' => $this->ico,
            'lawyer' => $this->lawyer,
            'day' => $this->day,
            'time' => $this->time,
            'branch' => $this->branch,
            'status' => $this->status,
            'tone' => $this->tone,
            'when' => $this->when_kind,
            // بيانات بطاقة الموعد الغنيّة (حقيقيّة)
            'client' => $this->user?->name,
            'consultRef' => $this->consult?->ref,
            'pay' => $this->consult?->paid_at ? 'مدفوع' : 'بانتظار السداد',
        ];
    }
}
