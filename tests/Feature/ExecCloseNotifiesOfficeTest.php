<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ExecFlow;
use App\Support\ExecService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **X4 — إغلاق ملفّ التنفيذ يُعلم المكتب لا العميل وحده** (ثبت في المتصفّح 2026-09-30: أغلقت الإدارة
 * EXE-2026-5518 فوصل الإشعار العميلَ وحده، ولم يعلم المحامي المسنَد).
 */
class ExecCloseNotifiesOfficeTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $this->lawyer->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $this->admin = User::factory()->create(['role' => Role::Admin]);
    }

    private function openExec(): Execution
    {
        return Execution::create([
            'user_id' => $this->client->id, 'number' => 'EXE-CL-'.uniqid(), 'subject' => 'تنفيذ حكم', 'sanad' => 'حكم قضائي',
            'amount' => 100000, 'stage' => 8, 'status' => ExecFlow::label(8), 'tone' => ExecFlow::tone(8),
            'paid' => true, 'exec_no' => 'EXE-TN-'.uniqid(), 'registered_at' => now()->subDay()->toDateString(),
            'assigned_lawyer_id' => $this->lawyer->id, 'assigned_lawyer' => $this->lawyer->name,
        ]);
    }

    private function closeBy(User $actor, Execution $exec): void
    {
        $this->actingAs($actor)->post(route('exec-flow.act', $exec), ['action' => 'close', 'reason' => ExecFlow::CLOSE_REASONS[0]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($exec->fresh()->isClosed());
    }

    private function closeNotices(User $user, Execution $exec): int
    {
        return UserNotification::where('user_id', $user->id)->where('body', 'like', "%{$exec->number}%")->where('body', 'like', '%أُغلق%')->count();
    }

    public function test_the_assigned_lawyer_learns_that_the_admin_closed_the_file(): void
    {
        $exec = $this->openExec();

        $this->closeBy($this->admin, $exec);

        $this->assertSame(1, $this->closeNotices($this->lawyer, $exec));
        $this->assertSame(1, $this->closeNotices($this->client, $exec), 'العميل برسالته كما كان');
    }

    /** الإداريّ الفاعل لا يُشعَر بفعله، وزميله الإداريّ يُشعَر. */
    public function test_the_acting_admin_is_not_told_of_his_own_act(): void
    {
        $colleague = User::factory()->create(['role' => Role::Admin]);
        $exec = $this->openExec();

        $this->closeBy($this->admin, $exec);

        $this->assertSame(0, $this->closeNotices($this->admin, $exec));
        $this->assertSame(1, $this->closeNotices($colleague, $exec));
    }

    public function test_the_admin_learns_that_the_lawyer_closed_the_file_and_the_lawyer_is_not_told_of_his_own_act(): void
    {
        $exec = $this->openExec();

        $this->closeBy($this->lawyer, $exec);

        $this->assertSame(1, $this->closeNotices($this->admin, $exec));
        $this->assertSame(0, $this->closeNotices($this->lawyer, $exec));
    }

    /** إغلاقٌ من النظام بلا مستخدم يكتمل — كان سطر الإشعار يقرأ اسم الفاعل بلا فحص فيتوقّف البريد والبثّ. */
    public function test_a_system_close_without_an_actor_completes(): void
    {
        $exec = $this->openExec();

        ExecService::close($exec, ExecFlow::CLOSE_REASONS[0]);

        $this->assertTrue($exec->fresh()->isClosed());
        $notice = UserNotification::where('user_id', $this->lawyer->id)->where('body', 'like', "%{$exec->number}%")->sole();
        $this->assertStringContainsString('أغلقه النظام', $notice->body);
    }
}
