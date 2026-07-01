<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ملخص ملف التذكرة — يجهّزه «الفريق القانوني» (الذكاء الاصطناعي) للمستشار:
 * تلخيص القضية + تلخيص المرفقات + تجهيز الوقائع + تحديد النقاط المهمة.
 * يراجعه المحامي ويعتمده، فيصل اعتماده إلى محادثة العميل.
 */
class TicketSummary extends Model
{
    protected $fillable = [
        'ticket_id', 'lawyer_id', 'case_summary', 'attachments_summary', 'facts', 'key_points', 'status', 'approved_at',
        'result', 'result_status',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function lawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lawyer_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    // الشكل الذي تتوقعه واجهة المحامي/الإدارة
    public function toData(): array
    {
        return [
            'id' => $this->id,
            'ref' => $this->ticket?->number,
            'caseSummary' => $this->case_summary,
            'attachmentsSummary' => $this->attachments_summary,
            'facts' => $this->facts,
            'keyPoints' => $this->key_points,
            'status' => $this->status,
            'approved' => $this->isApproved(),
            'result' => $this->result,
            'resultStatus' => $this->result_status,
        ];
    }
}
