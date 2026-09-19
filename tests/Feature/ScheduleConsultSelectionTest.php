<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use App\Support\AppointmentBoard;
use App\Support\ConsultBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * نموذج «جدولة موعد» يحجز للاستشارة التي اختارها الطاقم — لا لأقدم استشارةٍ مدفوعة للعميل.
 *
 * 🔴 كان النموذج يرسل العميل وحده، فيختار الخادم أقدم استشارةٍ «بانتظار تحديد الموعد» صامتاً: عميلٌ له
 * استشارتان مدفوعتان يُحجز موعده للأولى مهما قصد الموظّف الثانية.
 */
class ScheduleConsultSelectionTest extends TestCase
{
    use BuildsConsultJourney, RefreshDatabase;

    private function paidConsult(User $client, string $subject): Consult
    {
        return $this->priceAndPay(ConsultBooking::request($client, ['type' => 'office', 'subject' => $subject]));
    }

    /** @return array<string, mixed> */
    private function slot(User $lawyer): array
    {
        return ['date' => now()->addDay()->format('Y-m-d'), 'time' => '10:00', 'type' => 'office', 'lawyer_id' => $lawyer->id];
    }

    public function test_the_board_lists_paid_consults_awaiting_an_appointment_only(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $paid = $this->paidConsult($client, 'مدفوعة');
        $unpaid = ConsultBooking::request($client, ['type' => 'office', 'subject' => 'غير مدفوعة']);

        $listed = collect(AppointmentBoard::data($this->journeyAdmin())['awaitingConsults']);

        $this->assertTrue($listed->contains('id', $paid->id));
        $this->assertFalse($listed->contains('id', $unpaid->id), 'غير المدفوعة لا يُحجز لها موعد.');
        $this->assertSame('office', $listed->firstWhere('id', $paid->id)['type']);
        $this->assertSame($client->id, $listed->firstWhere('id', $paid->id)['clientId']);
    }

    public function test_the_appointment_goes_to_the_chosen_consult_not_the_oldest(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $older = $this->paidConsult($client, 'الأقدم');
        $chosen = $this->paidConsult($client, 'المختارة');

        $this->adminPublishes($chosen, $this->slot($lawyer))->assertSuccessful();

        $this->assertNotNull($chosen->fresh()->appointment_id, 'لم يُحجز موعد للاستشارة المختارة.');
        $this->assertNull($older->fresh()->appointment_id, 'حُجز الموعد لأقدم استشارة بدل المختارة.');
        $this->assertSame('بانتظار تحديد الموعد', $older->fresh()->status);
    }

    public function test_an_ambiguous_request_without_a_consult_is_refused(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $this->paidConsult($client, 'الأولى');
        $this->paidConsult($client, 'الثانية');

        $this->actingAs($this->journeyAdmin())
            ->postJson(route('admin.schedule.store'), ['client_id' => $client->id] + $this->slot($lawyer))
            ->assertStatus(422)
            ->assertJsonValidationErrors('consult_id');
    }

    public function test_an_unpaid_consult_cannot_be_scheduled(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $unpaid = ConsultBooking::request($client, ['type' => 'office', 'subject' => 'غير مدفوعة']);

        $this->adminPublishes($unpaid, $this->slot($lawyer))
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_id');
        $this->assertNull($unpaid->fresh()->appointment_id);
    }
}
