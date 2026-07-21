<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دعوة/طلب اجتماع (MR_FLOW): دعوة مُرسلة للعميل ← تأكيد حضور العميل ← تنفيذ الجلسة ← اعتماد الإدارة.
 */
class MeetRequest extends Model
{
    public const STAGE_SENT = 0;

    public const STAGE_CONFIRMED = 1;

    public const STAGE_EXECUTED = 2;

    public const STAGE_APPROVED = 3;

    protected $fillable = [
        'user_id', 'meeting_id', 'ref', 'service', 'type', 'case_ref',
        'day', 'time', 'sent_by', 'stage', 'meet_id', 'meet_link', 'host_link',
    ];

    protected $casts = ['stage' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function joinLink(): string
    {
        return $this->meet_link ?: config('services.zoom.fallback_base').$this->ref;
    }

    /**
     * بطاقة العميل لصفحة «دعوات الاجتماعات» — حقول العرض الآمنة فقط.
     * تستثني عمداً host_link (رابط المضيف/ZAK) واسم العميل والمرجع الداخلي.
     */
    public function toClientCard(): array
    {
        $confirmed = $this->stage >= self::STAGE_CONFIRMED;

        return [
            'id' => $this->ref,
            'dbId' => $this->id,
            'service' => $this->service,
            'type' => $this->type,
            'day' => $this->day,
            'time' => $this->time,
            'by' => $this->sent_by,
            'stage' => $this->stage,
            'meetLink' => $confirmed ? $this->joinLink() : null,
            // مرجع الاجتماع المرتبط — للدخول للغرفة المضمّنة (kind=meeting)
            'meetingRef' => $confirmed ? $this->meeting?->ref : null,
        ];
    }

    // يطابق واجهة MeetRequest في employee-data (id = المرجع النصّي)
    public function toCard(): array
    {
        $confirmed = $this->stage >= self::STAGE_CONFIRMED;

        return [
            'id' => $this->ref,
            'dbId' => $this->id,
            'client' => $this->user?->name ?? '—',
            'service' => $this->service,
            'type' => $this->type,
            'caseRef' => $this->case_ref,
            'day' => $this->day,
            'time' => $this->time,
            'by' => $this->sent_by,
            'stage' => $this->stage,
            'meetId' => $confirmed ? ($this->meet_id ?: $this->ref) : null,
            'meetLink' => $confirmed ? $this->joinLink() : null,
            'hostLink' => $confirmed ? $this->host_link : null,
            // مرجع الاجتماع المرتبط — للدخول للغرفة المضمّنة (kind=meeting)
            'meetingRef' => $confirmed ? $this->meeting?->ref : null,
        ];
    }
}
