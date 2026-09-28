<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * **«طلب استشارة نيابةً عن العميل»** — كان المسار `consults.request` جاهزاً في الخادم بلا زرٍّ يصله
 * (جولة التبويبات 2026-09-28). الزرّ في أربع صفحات، ودليل العملاء خاصّيّةٌ اختياريّة تُحمَّل عند الفتح.
 */
class ConsultOnBehalfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_the_client_directory_loads_only_when_the_form_opens(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'عميل المكتب']);
        Ticket::create(['user_id' => $client->id, 'number' => 'SB-OB-1', 'type' => 'نزاع', 'subject' => 'عقد', 'status' => 'قيد التحليل', 'tone' => 'b-blue']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        foreach (['admin.consult-requests', 'admin.calendar'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk()->assertInertia(fn (Assert $p) => $p
                ->missing('consultRequestForm')
                ->reloadOnly('consultRequestForm', fn (Assert $r) => $r
                    ->where('consultRequestForm.types.0.key', 'office')
                    ->where('consultRequestForm.clients', fn ($clients) => collect($clients)->contains(fn ($c) => $c['name'] === 'عميل المكتب'
                        && collect($c['items'])->contains(fn ($i) => $i['ref'] === 'SB-OB-1' && $i['kind'] === 'ticket')))));
        }
    }

    public function test_the_admin_requests_a_consult_for_a_client_and_it_awaits_pricing(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->post(route('admin.consults.request'), ['client_id' => $client->id, 'type' => 'video', 'subject' => 'استشارة عقد إيجار'])
            ->assertRedirect()->assertSessionHas('flash');

        $consult = Consult::where('user_id', $client->id)->firstOrFail();
        $this->assertSame([ConsultStatus::AwaitingPricing->value, 'مرئية', 'استشارة عقد إيجار'], [$consult->status, $consult->channel, $consult->subject]);
    }

    public function test_an_employee_needs_the_scheduling_permission(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        // الموظّف ينشأ بصلاحيّات دوره الافتراضيّة (ومنها الجدولة) — تُسحب ليُختبر الرفض
        $employee->syncPermissions([]);
        $employee->syncRoles([]);

        $this->actingAs($employee)->postJson(route('employee.consults.request'), ['client_id' => $client->id, 'type' => 'office'])->assertForbidden();
        $this->assertSame(0, Consult::where('user_id', $client->id)->count());

        $employee->givePermissionTo('جدولة المواعيد');
        $this->actingAs($employee)->post(route('employee.consults.request'), ['client_id' => $client->id, 'type' => 'office'])->assertRedirect();
        $this->assertSame(1, Consult::where('user_id', $client->id)->count());
    }

    public function test_the_button_is_placed_in_the_three_screens(): void
    {
        foreach (['admin/consult-requests', 'employee/consults', 'employee/schedule'] as $page) {
            $this->assertStringContainsString('<ConsultOnBehalfButton', (string) file_get_contents(resource_path("js/pages/{$page}.tsx")), $page);
        }
    }
}
