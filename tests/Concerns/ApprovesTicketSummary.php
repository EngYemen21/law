<?php

namespace Tests\Concerns;

use App\Models\Ticket;
use App\Models\TicketSummary;

/**
 * **ملخّصٌ معتمد بمرحلتيه (المحامي ثمّ الإدارة) — المقدّمة الواقعيّة لكلّ قرار مآل.**
 *
 * صار رفعُ مقترح المآل واعتمادُه يشترطان ملخّصاً معتمداً (ث٥، قرار المالك 2026-09-25). فكلّ
 * اختبارٍ يحكي تذكرةً بلغت قرار مآلها يلزمه هذا الملخّص كما في الرحلة الحقيقيّة — وإلّا رُدّ
 * بـ٤٢٢ واختبر الشرطَ بدل ما كُتب ليختبره.
 *
 * مصدرٌ واحد: كانت نسخةٌ في `BuildsConsultJourney` وأخرى خاصّةٌ في `TicketConvertContractTest`،
 * وكانت الإضافة ستصنع خمساً غيرهما.
 */
trait ApprovesTicketSummary
{
    protected function approveTicketSummary(Ticket $ticket): Ticket
    {
        TicketSummary::create([
            'ticket_id' => $ticket->id,
            'facts' => 'وقائع الملفّ كما أوردها العميل ومستنداته.',
            'key_points' => 'المطالبة بالمستحقات وديّاً ثمّ قضائياً.',
            'status' => 'approved',
            'lawyer_approved_at' => now(),
            'approved_at' => now(),
        ]);

        return $ticket;
    }
}
