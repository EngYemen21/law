<?php

namespace App\Models;

use App\Support\EventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * جلسة قضية — يجدولها المحامي ويسجّل نتيجتها، ويتابعها العميل.
 */
class CaseHearing extends Model
{
    protected $fillable = [
        'case_id', 'title', 'day', 'time', 'court', 'status', 'outcome',
        'starts_at', 'reminder_24h_sent_at', 'reminder_1h_sent_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'reminder_24h_sent_at' => 'datetime',
        'reminder_1h_sent_at' => 'datetime',
    ];

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CaseDocument::class, 'hearing_id');
    }

    /** صياغة الموعد الموحّدة (اليوم · الوقت) — من starts_at الحقيقي وإلا النصوص المخزّنة */
    public function label(): string
    {
        $day = $this->starts_at?->locale('ar')->translatedFormat('l d F Y') ?: (string) $this->day;

        return trim($day.($this->time ? ' · '.$this->time : ''));
    }

    /** جلسة مجدولة فات موعدها ولم تُسجَّل نتيجتها — الحالة المخزّنة «مجدولة» لا تتحدّث بمرور الوقت */
    public function isLapsed(): bool
    {
        // الموسومة فائتةً من المجدول فائتة بالتخزين لا بالاشتقاق
        if ($this->status === EventStatus::HEARING_LAPSED) {
            return true;
        }

        return $this->status === 'مجدولة'
            && $this->starts_at !== null
            && $this->starts_at->isPast();
    }

    public function toData(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            // يوم/وقت بصياغة عربية من starts_at الحقيقي عند وجوده، وإلا النصوص المخزّنة
            'day' => $this->starts_at?->locale('ar')->translatedFormat('l d F Y') ?: $this->day,
            'time' => $this->starts_at?->locale('ar')->translatedFormat('h:i A') ?: $this->time,
            'court' => $this->court,
            'status' => $this->status,
            'lapsed' => $this->isLapsed(), // للواجهة: شارة «فائتة — بانتظار النتيجة» بدل «مجدولة» الكاذبة
            'outcome' => $this->outcome,
            'startsAt' => $this->starts_at?->toIso8601String(), // لتعبئة نموذج التعديل في الواجهة
        ];
    }
}
