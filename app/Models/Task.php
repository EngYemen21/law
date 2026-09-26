<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    /** حالة المهمة المنجزة — الموضع الواحد لنصّها؛ الواجهة تقرأ `done` لا النصّ. */
    public const DONE = 'منجزة';

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
        return $this->status !== self::DONE && $this->due_at !== null && $this->due_at->copy()->endOfDay()->isPast();
    }

    // الشكل الذي تتوقعه واجهة المهام (يطابق LawyerTask)
    public function toData(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'ref' => $this->ref ?: '—',
            'owner' => $this->assignee?->name ?: '—',
            // التاريخ الحقيقيّ مقروءاً أوّلاً — كان النصّ المخزَّن يُعرض كما هو، فيظهر «2026-10-01» خاماً
            // لكلّ مهمّةٍ أُسندت بحقل التاريخ؛ والنصّ الحرّ («خلال أسبوع») يبقى لما لا تاريخ له
            'due' => $this->due_at?->locale('ar')->translatedFormat('d F Y') ?? ($this->due ?: '—'),
            'overdue' => $this->isOverdue(), // شارة «متأخرة» الحيّة
            'done' => $this->status === self::DONE,
            'status' => $this->status,
            'tone' => $this->isOverdue() ? 'b-red' : $this->tone,
        ];
    }
}
