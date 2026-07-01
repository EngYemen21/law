<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Meeting extends Model
{
    protected $fillable = [
        'user_id', 'title', 'when_label', 'is_up', 'has_link', 'has_minutes', 'has_summary',
    ];

    protected $casts = [
        'is_up' => 'boolean',
        'has_link' => 'boolean',
        'has_minutes' => 'boolean',
        'has_summary' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // الشكل الذي تتوقعه الواجهة (يطابق DATA.meetings)
    public function toCard(): array
    {
        return [
            'title' => $this->title,
            'when' => $this->when_label,
            'up' => $this->is_up,
            'link' => $this->has_link,
            'minutes' => $this->has_minutes,
            'summary' => $this->has_summary,
        ];
    }
}
