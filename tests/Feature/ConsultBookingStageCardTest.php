<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\SessionWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **نافذة الاستشارة عند الطاقم تقول المرحلة الفعليّة** (ملاحظة المالك 2026-09-27).
 *
 * سدّد العميل، فقالت النافذة «لا إسناد إلا بعد اكتمال التسعير والسداد وتحديد الموعد»، وشارةُ
 * «المسند: …» تعرض المحامي المنقول من التذكرة كأنّه أُسند — والإسناد في دورة الحجز يتمّ مع
 * اعتماد الموعد.
 */
class ConsultBookingStageCardTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    public function test_after_payment_the_card_says_what_is_actually_left(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->ticketWithApprovedOpinion($client, ['assigned_lawyer_id' => $lawyer->id]);

        $card = $this->requestPricedAndPaid($client, $ticket)->toCard();

        $this->assertSame('scheduling', $card['bookingStage']);
        $this->assertStringContainsString('سدّد العميل', (string) $card['assignBlocker']);
        $this->assertStringNotContainsString('السداد وتحديد', (string) $card['assignBlocker']);
        $this->assertTrue($card['lawyerTentative'], 'المحامي المنقول من التذكرة عُرض مُسنَداً');
    }

    public function test_no_room_before_the_appointment_is_approved(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketWithApprovedOpinion($client);
        $consult = $this->requestPricedAndPaid($client, $ticket);

        // رابطٌ أُطلق خطأً لا يفتح غرفةً لموعدٍ لم يُعتمد
        $consult->update(['channel' => 'مرئية', 'link_released_at' => now()]);

        $this->assertSame(SessionWindow::REFUSE_BOOKING, $consult->fresh()->joinBlocker());
        $this->assertFalse($consult->fresh()->canJoin());
    }
}
