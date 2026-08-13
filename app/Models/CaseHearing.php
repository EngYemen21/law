<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            'outcome' => $this->outcome,
            'startsAt' => $this->starts_at?->toIso8601String(), // لتعبئة نموذج التعديل في الواجهة
        ];
    }
}
