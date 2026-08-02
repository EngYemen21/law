<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * الاجتماع الكامل (FullMeeting): قبل/أثناء/بعد + محضر وملخص + اعتماد الإدارة + جلسة Zoom.
 */
class Meeting extends Model
{
    protected $fillable = [
        'user_id', 'ref', 'title', 'type', 'client_name', 'when_label', 'starts_at', 'reminder_sent_at',
        'status', 'priority', 'conf', 'attend', 'dur', 'approve',
        'before_items', 'during_items', 'after_items',
        'summary', 'sum_approved', 'minutes', 'participants', 'case_ref',
        'decisions', 'tasks_created',
        'meet_id', 'meet_link', 'host_link', 'meet_password', 'created_by',
        'assigned_lawyer_id', 'branch',
        'is_up', 'has_link', 'has_minutes', 'has_summary',
        'zoom_summary', 'zoom_summary_at',
        'recording_url', 'transcript_path', 'join_time', 'leave_time', 'duration_sec',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'zoom_summary_at' => 'datetime',
        'join_time' => 'datetime',
        'leave_time' => 'datetime',
        'is_up' => 'boolean',
        'has_link' => 'boolean',
        'has_minutes' => 'boolean',
        'has_summary' => 'boolean',
        'sum_approved' => 'boolean',
        'tasks_created' => 'boolean',
        'before_items' => 'array',
        'during_items' => 'array',
        'after_items' => 'array',
        'decisions' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // المحامي المسند بالمعرّف (لعزل الرؤية والبثّ)
    public function assignedLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_lawyer_id');
    }

    public function isUpcoming(): bool
    {
        return in_array($this->status, ['قادم', 'جارٍ'], true);
    }

    public function joinLink(): string
    {
        return $this->meet_link ?: config('services.zoom.fallback_base').($this->ref ?: 'M-'.$this->id);
    }

    // بطاقة العميل (يطابق DATA.meetings + viewMeetings) — المحضر/الملخص بعد اعتماد الإدارة فقط
    public function toCard(): array
    {
        $approved = $this->approve === 'معتمد';

        return [
            'id' => $this->id,
            'title' => $this->title,
            'when' => $this->when_label,
            'up' => $this->isUpcoming(),
            'ref' => $this->ref ?: 'M-'.$this->id,
            'link' => $this->isUpcoming() ? $this->joinLink() : '',
            'minutes' => $approved ? $this->minutes : null,
            'summary' => $approved ? $this->summary : null,
            // ملاحظة: العميل يرى المحضر/الملخص البشري المعتمَد فقط — لا ملخّص AI ولا رابط تسجيل
        ];
    }

    // بطاقة المكتب (تطابق واجهة FullMeeting في lawyer-data/admin-data)
    public function toFullCard(): array
    {
        return [
            'id' => $this->ref ?: 'M-'.$this->id,
            'dbId' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'client' => $this->client_name ?: 'داخلي',
            'when' => $this->when_label,
            'approve' => $this->approve,
            'before' => $this->before_items ?? [],
            'during' => $this->during_items ?? [],
            'after' => $this->after_items ?? [],
            'status' => $this->status,
            'priority' => $this->priority,
            'conf' => $this->conf,
            'attend' => $this->attend,
            'link' => $this->case_ref ?: ($this->client_name ?: '—'),
            'meetId' => $this->meet_id ?: ($this->ref ?: 'M-'.$this->id),
            'meetLink' => $this->joinLink(),
            'hostLink' => $this->host_link,
            'dur' => $this->dur ?: '60 دقيقة',
            'summary' => $this->summary,
            'zoomSummary' => $this->zoom_summary,
            'recording' => $this->recording_url,
            'sumApproved' => (bool) $this->sum_approved,
            'minutes' => $this->minutes,
            'participants' => $this->participants,
            'caseRef' => $this->case_ref,
            'decisions' => $this->decisions ?? [],
            'tasksCreated' => (bool) $this->tasks_created,
        ];
    }
}
