<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المحامي يبلغ صفحة رحلة الاستشارة، ولا يُعرض عليه «مقترح المسار» قبل انتهاء الجلسة
 * (ملاحظات المالك 2026-09-27).
 */
class LawyerConsultJourneyAccessTest extends TestCase
{
    use RefreshDatabase;

    private function consult(User $lawyer, string $status, ?Ticket $ticket = null): Consult
    {
        return Consult::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'ticket_id' => $ticket?->id,
            'ref' => 'CN-2026-'.uniqid(), 'subject' => 'مكافأة نهاية الخدمة', 'channel' => 'مرئية',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'day' => 'الأحد', 'time' => '10ص', 'when_label' => 'الأحد', 'session' => 'بانتظار', 'status' => $status,
        ]);
    }

    public function test_the_assigned_lawyer_opens_the_full_journey_page_and_others_cannot(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consult($lawyer, 'جديدة');

        $this->actingAs($lawyer)->get('/lawyer/consult?ref='.$consult->ref)
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('lawyer/consult'));

        // الرفض يصل رسالةً بإعادة توجيه (`ErrorResponse`) لا صفحةً — المهمّ أنّ الصفحة لا تُعرض له
        $this->actingAs($other)->get('/lawyer/consult?ref='.$consult->ref)->assertRedirect();
    }

    public function test_the_case_proposal_opens_only_after_the_session_ended(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = Ticket::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'number' => 'SB-J-'.uniqid(),
            'type' => 'عمالية', 'status' => 'موعد مؤكد', 'tone' => 'b-blue',
        ]);

        $this->assertFalse($this->consult($lawyer, 'جديدة', $ticket)->toCard()['canProposeOutcome']);
        $this->assertTrue($this->consult($lawyer, 'منتهية', $ticket)->toCard()['canProposeOutcome']);
        $this->assertFalse($this->consult($lawyer, 'منتهية')->toCard()['canProposeOutcome'], 'بلا تذكرة لا بطاقة قرار');
    }
}
