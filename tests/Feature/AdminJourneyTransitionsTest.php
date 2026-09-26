<?php

namespace Tests\Feature;

use App\Models\JourneyTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminJourneyTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'name' => 'مدير النظام',
            'email' => 'admin@test.com',
        ]);
    }

    private function createEmployee(): User
    {
        return User::factory()->create([
            'role' => 'employee',
            'name' => 'موظف العمليات',
            'email' => 'employee@test.com',
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/admin/journey-transitions');
        $response->assertRedirect('/login');
    }

    public function test_unauthorized_user_is_forbidden(): void
    {
        $employee = $this->createEmployee();

        $response = $this->actingAs($employee)->get('/admin/journey-transitions');
        $response->assertRedirect();
    }

    public function test_admin_can_view_journey_transitions_page(): void
    {
        $admin = $this->createAdmin();

        $transition = JourneyTransition::create([
            'entity_type' => 'Ticket',
            'entity_id' => 1,
            'entity_ref' => 'SB-2026-0001',
            'transition' => 'ticket.legal_opinion_published',
            'from_state' => 'بانتظار اعتماد الإدارة للملخّص',
            'to_state' => 'الرأي القانوني',
            'actor_id' => $admin->id,
            'reason' => 'تمت مراجعة واعتماد الرأي القانوني المبدئي.',
            'payload' => ['approved' => true],
        ]);

        $response = $this->actingAs($admin)->get('/admin/journey-transitions');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/journey-transitions')
            ->has('transitions.data', 1)
            ->where('transitions.data.0.entityRef', 'SB-2026-0001')
            ->where('transitions.data.0.transition', 'ticket.legal_opinion_published')
            ->where('transitions.data.0.toState', 'الرأي القانوني')
            ->has('stats')
            ->where('stats.total', 1)
            ->where('stats.withReason', 1)
            ->has('filters')
        );
    }

    public function test_admin_can_filter_by_entity_type(): void
    {
        $admin = $this->createAdmin();

        JourneyTransition::create([
            'entity_type' => 'Ticket',
            'entity_id' => 1,
            'entity_ref' => 'SB-2026-0001',
            'transition' => 'ticket.opened',
            'from_state' => null,
            'to_state' => 'قيد التحليل',
            'actor_id' => $admin->id,
        ]);

        JourneyTransition::create([
            'entity_type' => 'Consult',
            'entity_id' => 1,
            'entity_ref' => 'CS-2026-0001',
            'transition' => 'consult.request',
            'from_state' => null,
            'to_state' => 'بانتظار التسعير',
            'actor_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get('/admin/journey-transitions?entity_type=Ticket');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/journey-transitions')
            ->has('transitions.data', 1)
            ->where('transitions.data.0.entityRef', 'SB-2026-0001')
        );
    }

    public function test_admin_can_filter_by_search_query(): void
    {
        $admin = $this->createAdmin();

        JourneyTransition::create([
            'entity_type' => 'Ticket',
            'entity_id' => 1,
            'entity_ref' => 'SB-2026-9999',
            'transition' => 'ticket.close_justified',
            'from_state' => 'الرأي القانوني',
            'to_state' => 'مغلقة',
            'actor_id' => $admin->id,
            'reason' => 'عدم اختصاص المحاكم التجارية بالموضوع.',
        ]);

        $response = $this->actingAs($admin)->get('/admin/journey-transitions?search=عدم اختصاص');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/journey-transitions')
            ->has('transitions.data', 1)
            ->where('transitions.data.0.entityRef', 'SB-2026-9999')
        );
    }

    public function test_admin_can_export_csv(): void
    {
        $admin = $this->createAdmin();

        JourneyTransition::create([
            'entity_type' => 'Ticket',
            'entity_id' => 1,
            'entity_ref' => 'SB-2026-0001',
            'transition' => 'ticket.opened',
            'from_state' => null,
            'to_state' => 'قيد التحليل',
            'actor_id' => $admin->id,
            'reason' => 'فتح طلب جديد',
        ]);

        $response = $this->actingAs($admin)->get('/admin/journey-transitions/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('SB-2026-0001', $response->streamedContent());
    }
}
