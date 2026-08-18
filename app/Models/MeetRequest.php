<?php

namespace App\Models;

use App\Support\MeetingTime;
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

    public const STAGE_EXPIRED = 4;

    // أُلغي اجتماعها/دعوتها — سجلّ تاريخي يبقى للعميل بدل الحذف الصلب الذي كان يُخفي أثر الدعوة
    public const STAGE_CANCELLED = 5;

    protected $fillable = [
        'user_id', 'meeting_id', 'ref', 'service', 'type', 'case_ref',
        'day', 'time', 'sent_by', 'sent_by_id', 'assigned_lawyer_id', 'duration_min', 'stage', 'meet_id', 'meet_link', 'host_link',
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

    // المُرسِل (المحامي/الموظف الذي أرسل الدعوة) — أساس عزل الرؤية على جانب المكتب
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_id');
    }

    // المحامي/المختص المسؤول — يُسنَد للاجتماع عند تأكيد العميل
    public function assignedLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_lawyer_id');
    }

    public function canJoin(): bool
    {
        if (in_array($this->stage, [self::STAGE_EXPIRED, self::STAGE_CANCELLED], true)) {
            return false;
        }

        if ($this->stage >= self::STAGE_CONFIRMED) {
            if ($this->meeting) {
                return $this->meeting->canJoin();
            }

            // نافذة مغلقة الطرفين — كان الشرط مفتوحاً بعد الموعد فيبقى الزر فعّالاً للأبد.
            // تعذُّر تحليل التاريخ العربي الحرّ يُبقي السلوك السابق (متاح) — نفس فلسفة
            // «غير القابل للتحليل ليس ماضياً»، ويحسمه ربط الاجتماع أو المجدول لاحقاً.
            $startsAt = MeetingTime::parse($this->day, $this->time);
            if ($startsAt) {
                return now()->greaterThanOrEqualTo($startsAt->copy()->subMinutes(5))
                    && now()->lessThanOrEqualTo($startsAt->copy()->addMinutes(90));
            }

            return true;
        }

        return false;
    }

    public function joinLink(): string
    {
        return url('/meetingroom?ref='.($this->meeting?->ref ?: $this->ref));
    }

    /**
     * بطاقة العميل لصفحة «دعوات الاجتماعات» — حقول العرض الآمنة فقط.
     * تستثني عمداً host_link (رابط المضيف/ZAK) واسم العميل والمرجع الداخلي.
     */
    public function toClientCard(): array
    {
        $confirmed = $this->stage >= self::STAGE_CONFIRMED;
        $canJoin = $this->canJoin();

        return [
            'id' => $this->ref,
            'dbId' => $this->id,
            'service' => $this->service,
            'type' => $this->type,
            'day' => $this->day,
            'time' => $this->time,
            'by' => $this->sent_by,
            'stage' => $this->stage,
            'canJoin' => $canJoin,
            'meetLink' => $canJoin ? $this->joinLink() : null,
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
            // نافذة الدخول للمكتب أيضاً — أزرار الدخول كانت بلا أي بوابة زمنية على جانب المكتب
            'canJoin' => $this->canJoin(),
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
