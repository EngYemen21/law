<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Support\SettingsRegistry;
use App\Support\TicketAssignment;
use App\Support\TicketTriage;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **الإسناد قرارٌ بشريّ** (قرار المالك 2026-09-20).
 *
 * كان النظام يختار المحامي لحظة فتح التذكرة، ويختاره أيضاً عند إحالة الموظّف إن لم يكن مسنَداً —
 * فيصل الملفّ محاميّاً لم يقرّره أحد. الآن: الفتح لا يُسنِد، والإسناد من شاشتَي «تحويل التذاكر»
 * (الموظّف) و«توزيع التذاكر» (الإدارة)، وما بقي بلا محامٍ بعد مهلة الإعدادات (ساعتان) تُسنده
 * مهمّة التصعيد للإدارة العليا.
 *
 * هذا الملفّ يحرس القرار كلَّه: الفتح، والإحالة، والمهلة، وبقاء المسارين اليدويّين عاملين.
 */
class ManualAssignmentOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const DEPT = 'القسم التجاري';

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    private function lawyer(): User
    {
        return User::factory()->create([
            'role' => Role::Lawyer, 'status' => 'active',
            'distribution_mode' => 'auto', 'department' => self::DEPT,
        ]);
    }

    private function openTicket(User $client): Ticket
    {
        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'department' => self::DEPT, 'details' => 'تفاصيل الطلب.',
        ])->assertRedirect();

        return Ticket::latest('id')->firstOrFail();
    }

    /** المهلة نصف ساعة (قرار المالك 2026-10-02؛ كانت ساعتين): نافذةُ عملٍ للطاقم لا سباقٌ مع إسنادٍ آليّ. */
    public function test_the_escalation_window_is_half_an_hour(): void
    {
        $this->assertSame(30, SettingsRegistry::int('ticket_escalate_minutes'));
    }

    public function test_opening_a_ticket_leaves_it_unassigned(): void
    {
        $this->lawyer();
        User::factory()->create(['role' => Role::Admin]);

        $ticket = $this->openTicket($this->client());

        $this->assertNull($ticket->assigned_lawyer_id, 'عاد الإسناد التلقائيّ عند الفتح.');
        $this->assertNull($ticket->assigned_lawyer);
    }

    /** الدالّة التي كانت تُسنِد عند الفتح حُذفت — لا يعيدها منادٍ جديد بالخطأ. */
    public function test_the_automatic_first_assignment_helper_is_gone(): void
    {
        $this->assertFalse(method_exists(TicketAssignment::class, 'assign'));
    }

    /** الموظّف يُسنِد من «تحويل التذاكر» — المسار اليدويّ الأوّل. */
    public function test_an_employee_assigns_from_the_transfer_desk(): void
    {
        $this->seed(PermissionSeeder::class);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', ['تحويل التذاكر'])->get());
        $lawyer = $this->lawyer();
        $ticket = $this->openTicket($this->client());

        $this->actingAs($employee)->post(route('employee.transfer.do', $ticket), [
            'lawyer_id' => $lawyer->id,
        ])->assertRedirect();

        $this->assertSame($lawyer->id, $ticket->fresh()->assigned_lawyer_id);
    }

    /** الإدارة تُسنِد من «توزيع التذاكر» — المسار اليدويّ الثاني. */
    public function test_an_admin_assigns_from_the_distribute_screen(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = $this->lawyer();
        $ticket = $this->openTicket($this->client());

        $this->actingAs($admin)->post(route('admin.distribute.assign', $ticket), [
            'lawyer_id' => $lawyer->id,
        ])->assertRedirect();

        $this->assertSame($lawyer->id, $ticket->fresh()->assigned_lawyer_id);
    }

    /**
     * إحالة الموظّف على تذكرةٍ بلا محامٍ: تُصعَّد للإدارة العليا وتُسنَد لها — لا يُختار محامٍ
     * صامتاً، ولا تبقى الإحالة بلا صاحب ملفّ.
     */
    public function test_referring_without_an_assigned_lawyer_escalates_to_senior_management(): void
    {
        config(['services.ai_agent.enabled' => false]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $specialist = $this->lawyer(); // متخصّص متاح — ومع ذلك لا يُختار تلقائيّاً
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->openTicket($this->client());
        $ticket->update(['status' => 'قيد التحليل']);
        // بوّابة المستندات: زرّ الإحالة يطلب النواقص ما لم يكن في الملفّ مستندٌ صالح
        $ticket->documents()->create(['name' => 'العقد', 'status' => 'مرتبط', 'path' => 'ticket-docs/x.pdf']);

        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();

        $fresh = $ticket->fresh();
        $this->assertSame($admin->id, $fresh->assigned_lawyer_id, 'لم تُصعَّد الإحالة بلا محامٍ للإدارة العليا.');
        $this->assertNotSame($specialist->id, $fresh->assigned_lawyer_id, 'اختير محامٍ صامتاً.');
    }

    /**
     * **الإحالة خطوةٌ واحدة لا تنقسم.** بوّابة المتحكّم تُفحص قبل أيّ قفل، فنقرتان متسارعتان كانتا
     * تمرّان معاً وتريان الملفّ بلا ملخّص: ملخّصان ورسالتا إحالة للعميل. الآن الثانية تنتظر القفل
     * ثمّ ترى المرحلة قد غادرت مراحل الإحالة، فتُسجَّل ملاحظةً داخليّة ولا تكتب شيئاً.
     */
    public function test_a_double_referral_writes_one_summary_and_one_message(): void
    {
        config(['services.ai_agent.enabled' => false]);
        $lawyer = $this->lawyer();
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->openTicket($this->client());
        $ticket->update([
            'status' => 'قيد التحليل',
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
        ]);
        $ticket->documents()->create(['name' => 'العقد', 'status' => 'مرتبط', 'path' => 'ticket-docs/x.pdf']);

        TicketTriage::referToLawyer($ticket->fresh(), $employee);
        TicketTriage::referToLawyer($ticket->fresh(), $employee); // النقرة الثانية

        $this->assertSame(1, TicketSummary::where('ticket_id', $ticket->id)->count(), 'كُتب ملخّصٌ ثانٍ.');
        $this->assertSame(
            1,
            $ticket->messages()->where('role', 'إحالة')->count(),
            'وصلت العميلَ رسالتا إحالة.'
        );
        $this->assertSame('بانتظار اعتماد المستشار', $ticket->fresh()->status);
    }
}
