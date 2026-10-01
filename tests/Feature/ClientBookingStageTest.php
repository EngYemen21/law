<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Models\Consult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **لوحة الحجز في محادثة التذكرة — مرحلة الحجز بمفتاح الخادم.**
 *
 * كانت `pages/ticketchat.tsx` تقارن حالة الاستشارة بنصوصها العربيّة («بانتظار التسعير»…) في ستّة
 * مواضع. صارت بطاقة العميل تحمل `bookingStage` (التسعير · السداد · الموعد)، واعتمادُ الموعد الداخليّ
 * يُقرأ «الموعد» كما تقرؤه تسمية العميل.
 */
class ClientBookingStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_client_card_carries_the_booking_stage(): void
    {
        $expected = [
            ConsultStatus::AwaitingPricing->value => 'pricing',
            ConsultStatus::AwaitingPayment->value => 'payment',
            ConsultStatus::AwaitingSchedule->value => 'scheduling',
            // داخليّ — العميل لا يرى «اعتماد الموعد»
            ConsultStatus::AwaitingAppointmentApproval->value => 'scheduling',
            ConsultStatus::Ended->value => null,
            ConsultStatus::Cancelled->value => null,
        ];

        foreach ($expected as $status => $stage) {
            $card = (new Consult(['status' => $status, 'channel' => 'مرئية']))->toClientCard();
            $this->assertSame($stage, $card['bookingStage'], $status);
        }
    }

    public function test_the_staff_card_still_shows_the_approval_stage(): void
    {
        $this->assertSame('approval', (new Consult(['status' => ConsultStatus::AwaitingAppointmentApproval->value]))->bookingStage());
    }

    public function test_the_ticket_chat_compares_no_consult_status_text(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/ticketchat.tsx'));

        foreach (["=== 'بانتظار التسعير'", "=== 'بانتظار السداد'", "=== 'بانتظار تحديد الموعد'", "['بانتظار التسعير', 'بانتظار السداد', 'بانتظار تحديد الموعد']"] as $literal) {
            $this->assertStringNotContainsString($literal, $page, $literal);
        }
        $this->assertStringContainsString("stage === 'pricing'", $page);
    }
}
