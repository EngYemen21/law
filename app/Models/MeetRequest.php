<?php

namespace App\Models;

use App\Support\MeetingTime;
use App\Support\SessionWindow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دعوة/طلب اجتماع (MR_FLOW): بانتظار موافقة الإدارة ← معتمدة ومنشورة للعميل (مؤكَّدة) ← تنفيذ الجلسة ← اعتماد المحضر والملخص. (تأكيد العميل مُلغى)
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

    // المحامي/المختص المسؤول — يُسنَد للاجتماع عند موافقة الإدارة (MeetInvitation::schedule)
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
            // والطرف الأعلى مهلةُ الفوات من البداية (`SessionWindow`) — لا «+90» التي كانت
            // «المدّة + 30د»: الاجتماع لا مدّة له تنتهي بها (قرار المالك 2026-09-26).
            $startsAt = MeetingTime::parse($this->day, $this->time);

            return SessionWindow::joinOpened($startsAt) && ! SessionWindow::isMissed($startsAt);
        }

        return false;
    }

    public function joinLink(): string
    {
        return url('/meetingroom?ref='.($this->meeting?->ref ?: $this->ref));
    }

    /*
     * `toClientCard` أُزيلت (2026-09-26): صفحة «دعوات الاجتماعات» للعميل طُويت في /meetings
     * (بطاقة `Meeting::toCard`)، فلم يبقَ لها مُنادٍ — وكانت تحمل `'by' => sent_by`، أي
     * «الاسم الكامل (الدور)» لمن أرسل الدعوة. نسخةٌ ميّتة من بطاقة عميلٍ أوّلُ ما يُحيا منها التسريب.
     * والدعوة للعميل تُعرض عبر اجتماعها المعتمد وحده.
     */

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
            // لفحص إتاحة المحامي في مودال إعادة الإرسال (كان بلا فحص فيصطدم برفض الخادم)
            'lawyerId' => $this->assigned_lawyer_id,
            'meetId' => $confirmed ? ($this->meet_id ?: $this->ref) : null,
            'meetLink' => $confirmed ? $this->joinLink() : null,
            // لا `hostLink`: رابط المضيف (`start_url`) لا يغادر الخادم — الدخول من غرفة المنصّة وحدها (قرار المالك 2026-09-29)
            // مرجع الاجتماع المرتبط — للدخول للغرفة المضمّنة (kind=meeting)
            'meetingRef' => $confirmed ? $this->meeting?->ref : null,
        ];
    }
}
