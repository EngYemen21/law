<?php

namespace App\Models;

use App\Enums\Role;
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
        'zoom_uuid', 'zoom_share_url', 'zoom_audio_url', 'zoom_participants_log', 'zoom_ai_next_steps',
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
        'zoom_participants_log' => 'array',
        'zoom_ai_next_steps' => 'array',
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

    /**
     * رابط دخول الجلسة المضمّنة داخل المنصّة حصراً (لا روابط خارجية تخرج المستخدم عن المنصة).
     */
    public function joinLink(?User $user = null): string
    {
        $ref = $this->ref ?: 'M-'.$this->id;

        if ($user) {
            return match ($user->role) {
                Role::Client => url('/meetingroom?ref='.$ref),
                Role::Lawyer => url('/lawyer/meetingroom?ref='.$ref),
                Role::Employee => url('/employee/meetingroom?ref='.$ref),
                Role::Admin => url('/admin/meetingroom?ref='.$ref),
                default => url('/meetingroom?ref='.$ref),
            };
        }

        return url('/meetingroom?ref='.$ref);
    }

    /**
     * رابط تبويب الاجتماعات/الدعوات بحسب دور المستخدم (لتوجيهه عند الدخول للمنصة).
     */
    public function portalUrlFor(?User $user = null): string
    {
        if (! $user) {
            return url('/meetreqs');
        }

        return match ($user->role) {
            Role::Client => url('/meetreqs'),
            Role::Lawyer => url('/lawyer/meetreqs'),
            Role::Employee => url('/employee/meetreqs'),
            Role::Admin => url('/admin/meetmgmt'),
            default => url('/meetreqs'),
        };
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
            'lawyer' => $this->assignedLawyer?->name ?: '—',
            'branch' => $this->branch ?: '—',
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
            'transcript' => (bool) $this->transcript_path,
            // بيانات جلسة Zoom الفعلية (تُملأ عبر الويبهوك) — للإدارة العليا
            'startsAt' => $this->starts_at?->toIso8601String(),
            'joinTime' => $this->join_time?->format('Y-m-d H:i'),
            'leaveTime' => $this->leave_time?->format('Y-m-d H:i'),
            'durationSec' => $this->duration_sec !== null ? (int) $this->duration_sec : null,
            'zoomSummaryAt' => $this->zoom_summary_at?->format('Y-m-d H:i'),
            'reminderSentAt' => $this->reminder_sent_at?->format('Y-m-d H:i'),
            'createdBy' => $this->created_by,
            'sumApproved' => (bool) $this->sum_approved,
            'minutes' => $this->minutes,
            'participants' => $this->participants,
            'caseRef' => $this->case_ref,
            'decisions' => $this->decisions ?? [],
            'tasksCreated' => (bool) $this->tasks_created,
            'zoomUuid' => $this->zoom_uuid,
            'zoomShareUrl' => $this->zoom_share_url,
            'zoomAudioUrl' => $this->zoom_audio_url,
            'zoomParticipantsLog' => $this->zoom_participants_log ?? [],
            'zoomAiNextSteps' => $this->zoom_ai_next_steps ?? [],
        ];
    }
}
