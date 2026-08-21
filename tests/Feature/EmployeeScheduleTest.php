<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_employee_can_view_schedule_page_with_rich_props(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', ['جدولة المواعيد'])->get());

        $client = User::factory()->create([
            'role' => Role::Client,
            'name' => 'عميل تجريبي',
        ]);

        $lawyer = User::factory()->create([
            'role' => Role::Lawyer,
            'name' => 'محامٍ تجريبي',
            'department' => 'قسم الشركات',
            'status' => 'active',
        ]);

        Appointment::create([
            'user_id' => $client->id,
            'lawyer_id' => $lawyer->id,
            'ext_id' => 'AP-SCHED-1',
            'type' => 'استشارة حضورية',
            'ico' => 'office',
            'lawyer' => $lawyer->name,
            'day' => now()->format('Y-m-d'),
            'time' => '10:00',
            'starts_at' => now()->addDay()->setHour(10)->setMinute(0),
            'duration_min' => 60,
            'place' => 'الرياض',
            'status' => 'مؤكد',
            'tone' => 'b-green',
            'when_kind' => 'up',
        ]);

        $response = $this->actingAs($employee)->get('/employee/schedule');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('employee/schedule')
            ->has('clients')
            ->has('lawyers')
            ->has('appointments')
            ->has('counts')
            ->has('counts.today')
            ->has('counts.upcoming')
            ->has('counts.video')
            ->has('counts.office')
        );
    }

    public function test_employee_can_fetch_slots_and_create_schedule(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', ['جدولة المواعيد'])->get());

        $client = User::factory()->create([
            'role' => Role::Client,
            'name' => 'عميل حجز',
        ]);

        $lawyer = User::factory()->create([
            'role' => Role::Lawyer,
            'name' => 'محامي حجز',
            'status' => 'active',
        ]);

        $tomorrow = now()->addDay()->format('Y-m-d');

        // فحص الفترات
        $slotsRes = $this->actingAs($employee)->getJson("/employee/schedule/slots?lawyer_id={$lawyer->id}&date={$tomorrow}");
        $slotsRes->assertOk();
        $slotsRes->assertJsonStructure(['slots']);

        // إنشاء حجز استشارة
        $postRes = $this->actingAs($employee)->post('/employee/schedule', [
            'client_id' => $client->id,
            'lawyer_id' => $lawyer->id,
            'type' => 'office',
            'subject' => 'استشارة جديدة',
            'date' => $tomorrow,
            'time' => '11:00',
        ]);

        $postRes->assertSessionHasNoErrors();
        $this->assertDatabaseHas('appointments', [
            'user_id' => $client->id,
            'lawyer_id' => $lawyer->id,
        ]);
        $this->assertDatabaseHas('consults', [
            'user_id' => $client->id,
            'assigned_lawyer_id' => $lawyer->id,
            'channel' => 'حضورية',
        ]);
    }
}
