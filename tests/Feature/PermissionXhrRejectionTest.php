<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * حارس الصلاحيات كان يعيد تحويلاً (302) لكل الطلبات، وطلبات axios تتبع التحويل
 * فتقرأ 200 ويشتعل then() → توست «✅ تم…» بلا أي تنفيذ. الرفض الآن صريح لنداءات XHR.
 */
class PermissionXhrRejectionTest extends TestCase
{
    use RefreshDatabase;

    private function ticketWithEmployee(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-9001',
            'type' => 'استشارة قانونية',
            'department' => 'القانون التجاري',
            'status' => 'قيد التحليل',
            'tone' => 'b-blue',
            'branch' => 'الرياض',
        ]);

        // المصنع يمنح الموظف كل الصلاحيات افتراضياً — نقصرها هنا على فتح الصفحة دون الإجراءات الحسّاسة
        $employee = User::factory()->create(['role' => Role::Employee, 'branch' => 'الرياض']);
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة التذاكر'])->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$ticket, $employee];
    }

    public function test_xhr_action_without_permission_is_rejected_with_403(): void
    {
        [$ticket, $employee] = $this->ticketWithEmployee();

        $this->actingAs($employee)
            ->postJson(route('employee.tickets.reqdocs', $ticket), ['docs' => ['صورة الهوية']])
            ->assertStatus(403);

        // ولم يُنشأ أي أثر للطلب: لا رسالة نواقص ولا تغيير للحالة
        $this->assertFalse($ticket->fresh()->messages->contains(fn ($m) => $m->role === 'نواقص'));
        $this->assertSame('قيد التحليل', $ticket->fresh()->status);
    }

    public function test_page_visit_without_permission_still_redirects(): void
    {
        [, $employee] = $this->ticketWithEmployee();

        // زيارات الصفحات تبقى تحويلاً للوحة المستخدم مع رسالة — لا 403 خام
        $this->actingAs($employee)
            ->get(route('employee.transfer'))
            ->assertRedirect(Role::Employee->home())
            ->assertSessionHas('error');
    }

    public function test_permitted_employee_passes_through(): void
    {
        [$ticket, $employee] = $this->ticketWithEmployee();
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة التذاكر', 'الرد على العملاء'])->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($employee)
            ->postJson(route('employee.tickets.reqdocs', $ticket), ['docs' => ['صورة الهوية']])
            ->assertNoContent();

        $this->assertTrue($ticket->fresh()->messages->contains(fn ($m) => $m->role === 'نواقص'));
    }
}
