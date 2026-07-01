<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * جلسة قضية — يجدولها المحامي ويسجّل نتيجتها، ويتابعها العميل.
 */
class CaseHearing extends Model
{
    protected $fillable = ['case_id', 'title', 'day', 'time', 'court', 'status', 'outcome'];

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    public function toData(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'day' => $this->day,
            'time' => $this->time,
            'court' => $this->court,
            'status' => $this->status,
            'outcome' => $this->outcome,
        ];
    }
}
