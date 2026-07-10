<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_branch(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.branches.store'), [
            'name' => 'فرع أبها', 'city' => 'أبها', 'phone' => '017 000 0000',
        ])->assertRedirect();

        $this->assertDatabaseHas('branches', ['name' => 'فرع أبها']);
    }

    public function test_duplicate_branch_name_rejected(): void
    {
        Branch::create(['name' => 'فرع جدة', 'city' => 'جدة']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.branches.store'), [
            'name' => 'فرع جدة', 'city' => 'جدة',
        ])->assertSessionHasErrors('name');
    }

    public function test_client_cannot_create_branch(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->post(route('admin.branches.store'), [
            'name' => 'فرع', 'city' => 'مدينة',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseCount('branches', 0);
    }
}
