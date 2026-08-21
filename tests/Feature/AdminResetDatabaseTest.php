<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminResetDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_non_admin_cannot_reset_database(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($client)->post(route('admin.reset-database'))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($employee)->post(route('admin.reset-database'))
            ->assertRedirect(route('employee.dashboard'));
    }

    public function test_admin_can_reset_database_keeping_users_and_roles(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        // Create some operational data
        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-9999',
            'type' => 'تجاري',
            'status' => 'مفتوحة',
            'tone' => 'b-blue',
        ]);

        $case = LegalCase::create([
            'user_id' => $client->id,
            'number' => 'CASE-2026-9999',
            'type' => 'تجاري',
            'status' => 'منظورة',
            'tone' => 'b-blue',
        ]);

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
        $this->assertDatabaseHas('cases', ['id' => $case->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseHas('users', ['id' => $client->id]);

        // Perform reset
        $response = $this->actingAs($admin)->post(route('admin.reset-database'), ['confirm' => 'RESET']);
        $response->assertRedirect(route('admin.dashboard'));

        // Operational tables must be empty
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('cases', 0);

        // Users and Admin must still exist
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseHas('users', ['id' => $client->id]);
    }
}
