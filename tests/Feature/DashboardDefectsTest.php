<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AdminDashboardService;
use App\Support\ExecFlow;
use App\Support\ExecService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * أعطال اللوحات (الدفعة أ) — كل اختبار يمثّل زرًّا أو حسابًا كان مكسورًا فعلاً.
 */
class DashboardDefectsTest extends TestCase
{
    use RefreshDatabase;

    // ── مسار جلسات استشارات المحامي: المتحكّم كان يصيّر lawyer/consults بلا مسار ولا صفحة ──

    public function test_lawyer_consults_route_renders_its_page(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-A-1', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'session' => 'بانتظار الجلسة', 'status' => 'جديدة',
        ]);

        $this->actingAs($lawyer)->get(route('lawyer.consults'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('lawyer/consults')->has('consults', 1));
    }

    // ── الضريبة: كانت مصلّبة 0.15 في القضايا والتنفيذ بينما الاستشارات تقرأ الإعداد ──

    public function test_case_fee_vat_follows_admin_setting(): void
    {
        Setting::put('vat_rate', 5);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-VAT-1', 'type' => 'تجاري',
            'status' => 'بانتظار اعتماد الأتعاب', 'tone' => 'b-amber', 'fee_status' => 'none',
        ]);

        $this->actingAs($admin)->post(route('admin.cases.fee', $case), ['fee' => 10000])->assertRedirect();

        // الضريبة تظهر في نصّ الفاتورة وفي مبلغها (لا عمود vat على القضية)
        $this->assertStringContainsString('ضريبة 500', (string) $case->fresh()->invoice_text);
        $this->assertSame(10500, (int) Invoice::where('case_id', $case->id)->firstOrFail()->amount); // 5% لا 15%
    }

    public function test_execution_fee_vat_follows_admin_setting(): void
    {
        Setting::put('vat_rate', 5);
        $client = User::factory()->create(['role' => Role::Client]);
        // التسعير يلزمه محامٍ مسنَد (قرار المالك 2026-09-12) — والمقيس هنا نسبة الضريبة لا الحارس
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $execution = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-VAT-1', 'subject' => 'تنفيذ',
            // الحالة من المرحلة كما يكتبها النظام — «جديد» لم يكتبها أيّ كودٍ قطّ
            'status' => ExecFlow::label(3), 'tone' => 'b-blue', 'last_action' => 'فتح', 'stage' => 3,
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        ExecService::setFee($execution, 10000, '30 يوم', 'fixed');

        $this->assertSame(500, (int) $execution->fresh()->vat);
    }

    // ── حساب الإدارة المُنشأ من شاشة الموظفين كان يختفي من الجدول ──

    public function test_admin_accounts_appear_in_staff_list(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        User::factory()->create(['role' => Role::Employee]);
        User::factory()->create(['role' => Role::Lawyer]);

        $this->actingAs($admin)->get(route('admin.staff'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/staff')->has('staff', 3));
    }

    public function test_staff_card_exposes_role_key_for_admin_guarding(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->assertSame('admin', $admin->staffCard()['roleKey']);
    }

    // ── تقرير الاستشارة: كان مسجّلاً تحت role:client فقط، فزرّ المكتب توست بلا مسار ──

    public function test_office_roles_can_open_consult_report(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-A-2', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'session' => 'منتهية', 'status' => 'مكتملة', 'summary' => 'ملخص الجلسة',
        ]);

        $this->actingAs($lawyer)->get(route('consults.report.plain', $consult))->assertOk();
        $this->actingAs($client)->get(route('consults.report.plain', $consult))->assertOk();
    }

    // ── عدّادات التنفيذ: المرفوض ليس نشطاً، والعدّاد يقرأ القاعدة لا الصفحة ──

    private function exec(User $client, string $number, array $over = []): Execution
    {
        return Execution::create(array_merge([
            'user_id' => $client->id, 'number' => $number, 'subject' => 'تنفيذ',
            'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2, 'amount' => 50000,
        ], $over));
    }

    /**
     * `reject` يكتب القرار ولا ينقل المرحلة عمداً (المرفوض ليس مغلقاً)، فكان الطلب المرفوض
     * يُعَدّ «تنفيذاً نشطاً» وتُجمع قيمة مطالبته في المبلغ النشط — رقمٌ ماليّ يضمّ ما لن يُنفَّذ.
     */
    public function test_rejected_executions_leave_the_admin_active_counters(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->exec($client, 'EXE-ACT-1');                            // بلا قرار بعد (decision = null)
        $this->exec($client, 'EXE-ACT-2', ['decision' => 'مقبول']);
        $this->exec($client, 'EXE-REJ-1', ['decision' => 'مرفوض']);

        $overview = app(AdminDashboardService::class)->get360Data(true)['overview'];

        $this->assertSame(2, $overview['activeExecutions'], 'المرفوض يخرج، وغير المبتوت فيه يبقى');
        $this->assertSame(100000, $overview['activeExecAmount']);
    }

    /** عدّاد لوحة الموظّف كان يعدّ مجموعةً مقصوصة بـtake(5): «٥ نشطة» والمكتب فيه أكثر. */
    public function test_employee_active_execution_counter_counts_the_database_not_the_page(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        foreach (range(1, 7) as $i) {
            $this->exec($client, "EXE-E-{$i}");
        }
        $this->exec($client, 'EXE-E-CLOSED', ['stage' => 9, 'status' => 'مغلق']); // المنتهي ليس نشطاً

        $this->actingAs($employee)->get(route('employee.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('execs', 5)->where('counts.activeExecs', 7));
    }

    /**
     * `/lawyer/execs` يشترط «إدارة القضايا والأتعاب»، ولوحةُ المحامي بلا وسيط صلاحيّة كانت
     * تشحن صفوف التنفيذ (أسماء الموكّلين ومواضيعهم) لكلّ محامٍ ولو نُزعت عنه الصلاحيّة.
     */
    public function test_lawyer_dashboard_ships_executions_only_behind_the_permission(): void
    {
        $this->seed(PermissionSeeder::class);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $this->exec($client, 'EXE-L-1', ['assigned_lawyer_id' => $lawyer->id]);

        $lawyer->syncPermissions([]);
        $this->actingAs($lawyer)->get(route('lawyer.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('executions', 0)->where('stats.activeExecutions', 0));

        $lawyer->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $this->actingAs($lawyer)->get(route('lawyer.dashboard'))
            ->assertInertia(fn ($p) => $p->has('executions', 1));
    }

    // ── بطاقة الموعد: رابط جلسة حقيقي داخل المنصّة لا رابط مختلق ──

    public function test_appointment_card_carries_real_session_link(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-A-1', 'type' => 'تجاري',
            'status' => 'قيد المعالجة', 'tone' => 'b-blue',
        ]);

        $card = Appointment::create([
            'user_id' => $client->id, 'ticket_id' => $ticket->id, 'ext_id' => 'AP-A-1',
            'type' => 'استشارة مرئية', 'ico' => 'video', 'lawyer' => 'أ. سارة',
            'day' => 'الأحد', 'time' => '10:00', 'starts_at' => now()->addDay(),
            'duration_min' => 45, 'place' => 'اجتماع إلكتروني', 'status' => 'مؤكد',
            'tone' => 'b-green', 'when_kind' => 'up',
        ])->toCard();

        $this->assertStringNotContainsString('salaselbabel.net/APT-', (string) $card['joinLink']);
    }
}
