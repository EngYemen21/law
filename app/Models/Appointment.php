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

    /** ربط الراوت برقم العمل (ext_id) لا المعرّف الداخلي — يطابق نمط LegalCase/Invoice. */
    public function getRouteKeyName(): string
    {
        return 'ext_id';
    }

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

    /** يوم الموعد بصياغة عربية مقروءة (الاثنين ٢٩ يونيو ٢٠٢٦) من starts_at الحقيقي؛ يرجع للنص المخزَّن إن غاب. */
    public function dayLabel(): string
    {
        return $this->starts_at?->locale('ar')->translatedFormat('l d F Y') ?: (string) $this->day;
    }

    /** وقت الموعد بصياغة عربية مقروءة (١١:٣٠ ص) من starts_at الحقيقي؛ يرجع للنص المخزَّن إن غاب. */
    public function timeLabel(): string
    {
        return $this->starts_at?->locale('ar')->translatedFormat('h:i A') ?: (string) $this->time;
    }

    // الشكل الذي تتوقعه الواجهة (يطابق DATA.appts)
    public function toCard(): array
    {
        return [
            'id' => $this->ext_id,
            'type' => $this->type,
            'ico' => $this->ico,
            'lawyer' => $this->lawyer,
            'day' => $this->dayLabel(),
            'time' => $this->timeLabel(),
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
