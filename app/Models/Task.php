<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    protected $fillable = ['assigned_to', 'title', 'ref', 'due', 'due_at', 'completed_at', 'status', 'tone'];

    protected $casts = [
        'due_at' => 'date',
        'completed_at' => 'datetime',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** تجاوزت استحقاقها دون إنجاز — «due» النصية («خلال أسبوع» الأبدية) لا تصلح للحساب */
    public function isOverdue(): bool
    {
        return $this->status !== 'منجزة' && $this->due_at !== null && $this->due_at->copy()->endOfDay()->isPast();
    }

    // الشكل الذي تتوقعه واجهة المهام (يطابق LawyerTask)
    public function toData(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'ref' => $this->ref ?: '—',
            'owner' => $this->assignee?->name ?: '—',
            'due' => $this->due ?: ($this->due_at?->locale('ar')->translatedFormat('d F Y') ?? '—'),
            'overdue' => $this->isOverdue(), // شارة «متأخرة» الحيّة
            'status' => $this->status,
            'tone' => $this->isOverdue() ? 'b-red' : $this->tone,
        ];
    }
}
