<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **لا زرّ لما يرفضه الخادم** — تدقيق 2026-09-29: أزرارٌ تظهر لموظّفٍ أو محامٍ بلا صلاحيّتها ثمّ يُردّ عند
 * الضغط. الشقّ الخادميّ (الرفض) يُثبت هنا، والشقّ الواجهيّ (الإخفاء) بحارسٍ على مصدر الصفحات.
 */
class HiddenWithoutPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function staff(Role $role, array $perms): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $user->syncPermissions(Permission::whereIn('name', $perms)->get());

        return $user;
    }

    public function test_server_refuses_what_the_buttons_offered(): void
    {
        $employee = $this->staff(Role::Employee, ['إدارة التذاكر']);
        $this->assertPageRefused($this->actingAs($employee)->get('/employee/transfer'));
        $this->assertPageRefused($this->actingAs($employee)->get('/employee/schedule'));

        $lawyer = $this->staff(Role::Lawyer, ['استقبال الاستشارات']);
        $this->assertPageRefused($this->actingAs($lawyer)->get('/lawyer/assistant'));
        $this->actingAs($lawyer)->postJson('/lawyer/tasks', ['title' => 'مهمة'])->assertForbidden();
    }

    public function test_pages_gate_the_buttons_by_permission(): void
    {
        $src = fn (string $p) => (string) file_get_contents(resource_path("js/pages/{$p}.tsx"));

        $this->assertMatchesRegularExpression("/canTransfer && \\(\\s*<button[\s\S]{0,120}?router\\.visit\\('\\/employee\\/transfer'\\)/s", $src('employee/tickets'));
        $this->assertMatchesRegularExpression("/canSchedule && \\(\\s*<button[\s\S]{0,120}?router\\.visit\\('\\/employee\\/schedule'\\)/s", $src('employee/tickets'));
        $this->assertMatchesRegularExpression("/can\\('المساعد القانوني'\\) && \\(\\s*<button[\s\S]{0,120}?onClick=\\{openAssistant\\}/s", $src('lawyer/tickets'));
        $this->assertStringContainsString("canPropose={base === '/admin' || canManageCases}", $src('lawyer/ticketchat'));
        $this->assertMatchesRegularExpression("/canManageTasks && \\(\\s*<button[\s\S]{0,120}?setTaskModalOpen\\(true\\)/s", $src('lawyer/dashboard'));
    }
}
