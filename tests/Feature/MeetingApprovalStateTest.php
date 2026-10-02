<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\MeetingApproval;
use App\Domain\Journey\Enums\MeetingStatus;
use App\Events\MeetingStatusBroadcast;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **شارة اعتماد المحضر مشتقّةٌ لا نصّ العمود** (قرار المالك 2026-10-02).
 *
 * ثبت في `/admin/meetmgmt`: اجتماعٌ «ملغى» وآخر «قادم» يحملان «بانتظار اعتماد الإدارة» — قيمة العمود الافتراضيّة
 * عند الإنشاء، تُعرض كما هي. والاعتماد اعتمادُ المحضر بعد الانتهاء: فلا شارة لما لم ينتهِ.
 */
class MeetingApprovalStateTest extends TestCase
{
    use RefreshDatabase;

    private function meeting(MeetingStatus $status, array $extra = []): Meeting
    {
        return Meeting::create([
            'ref' => 'M-AP-'.uniqid(), 'title' => 'اجتماع', 'type' => 'اجتماع مع عميل', 'when_label' => 'اليوم',
            'starts_at' => now()->addHour(), 'status' => $status->value,
        ] + $extra);
    }

    public function test_only_an_ended_meeting_has_an_approval_badge(): void
    {
        foreach ([MeetingStatus::Upcoming, MeetingStatus::Live, MeetingStatus::Cancelled, MeetingStatus::Missed, MeetingStatus::Postponed] as $status) {
            $m = $this->meeting($status);
            $this->assertSame('بانتظار اعتماد الإدارة', $m->fresh()->approve, 'الافتراض في العمود باقٍ — والعرض لا يقرؤه');
            $this->assertNull($m->approvalState(), $status->value);
            $this->assertNull($m->toFullCard()['approval'], $status->value);
        }
    }

    public function test_an_ended_meeting_waits_for_minutes_then_for_approval(): void
    {
        $noOutput = $this->meeting(MeetingStatus::Ended);
        $this->assertSame(MeetingApproval::AwaitingMinutes, $noOutput->approvalState());
        $this->assertFalse($noOutput->canApprove(), 'بلا مخرجات لا زرّ اعتماد');

        $withMinutes = $this->meeting(MeetingStatus::Ended, ['minutes' => 'محضر الاجتماع الفعليّ']);
        $this->assertSame(MeetingApproval::AwaitingApproval, $withMinutes->approvalState());
        $this->assertTrue($withMinutes->canApprove(), 'الشارة والزرّ حكمٌ واحد');
        $this->assertSame(['key' => 'awaiting_approval', 'label' => 'بانتظار اعتماد المحضر', 'tone' => 'b-amber'], $withMinutes->toFullCard()['approval']);

        $approved = $this->meeting(MeetingStatus::Ended, ['minutes' => 'محضر', 'approve' => Meeting::APPROVED]);
        $this->assertSame(['key' => 'approved', 'label' => 'معتمد', 'tone' => 'b-green'], $approved->toFullCard()['approval']);

        // سجلٌّ قديم يحمل «معتمد» على ملغى (لا يقع في المسار الحيّ) — لا يُعرض معتمداً
        $this->assertNull($this->meeting(MeetingStatus::Cancelled, ['approve' => Meeting::APPROVED])->approvalState());
    }

    public function test_the_card_and_the_broadcast_carry_the_same_state_not_the_column(): void
    {
        $cancelled = $this->meeting(MeetingStatus::Cancelled);
        $ended = $this->meeting(MeetingStatus::Ended, ['summary' => 'ملخّص الجلسة الفعليّ']);

        foreach ([$cancelled, $ended] as $m) {
            $card = $m->toFullCard();
            $this->assertArrayNotHasKey('approve', $card, 'نصّ العمود لا يصل الشاشة');
            $this->assertSame($card['approval'], (new MeetingStatusBroadcast($m))->broadcastWith()['approval']);
        }
        $this->assertArrayNotHasKey('approve', (new MeetingStatusBroadcast($ended))->broadcastWith());
    }
}
