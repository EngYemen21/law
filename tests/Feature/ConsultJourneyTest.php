<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحقّق من رحلة معالجة الاستشارة (CONSULT_FLOW):
 * استقبال → مراجعة الموظف → معالجة الفريق القانوني → اعتماد الموظف → إحالة للمحامي.
 */
class ConsultJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function makeConsult(User $client, array $extra = []): Consult
    {
        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-2026-6001',
            'subject' => 'نزاع تجاري مع مورّد',
            'type' => 'تجاري',
            'channel' => 'مرئية',
            'lawyer' => 'أ. سارة القحطاني',
            'day' => 'الاثنين 29 يونيو',
            'time' => '11:30 ص',
            'when_label' => 'الاثنين 29 يونيو · 11:30 ص',
            'status' => 'جديدة',
        ], $extra));
    }

    public function test_booking_enters_journey_as_new(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-8888',
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'status' => 'بانتظار حجز الاستشارة',
            'tone' => 'b-amber',
        ]);

        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);

        // الدورة الكاملة: طلب → تسعير الإدارة → دفع محاكى → اختيار الموعد → دخول الرحلة «جديدة»
        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'video'])->assertNoContent();
        $consult = Consult::where('ticket_id', $ticket->id)->firstOrFail();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => 450])->assertRedirect();
        ConsultBooking::markPaid($consult->fresh());
        $this->actingAs($client)->post(route('consults.schedule', $consult), [
            'lawyer_id' => $lawyer->id, 'date' => LawyerAvailability::resolveDate(null)->toDateString(), 'time' => '11:30',
        ])->assertRedirect();

        $consult->refresh();
        $this->assertSame('جديدة', $consult->status);
        $this->assertSame('التجاري', $consult->type);
        $this->assertSame('متوسطة', $consult->priority);
        $this->assertNotEmpty($consult->audit);
    }

    public function test_full_journey_take_analyze_approve_refer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee, 'name' => 'منيرة الحربي']);
        // محامٍ حقيقي في قاعدة البيانات — الاحتياط يقترح محامياً فعلياً لا اسماً مُختلَقاً
        User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سارة القحطاني']);
        $consult = $this->makeConsult($client);

        // استلام
        $this->actingAs($employee)->post(route('employee.consults.take', $consult))->assertRedirect();
        $consult->refresh();
        $this->assertSame('قيد مراجعة الموظف', $consult->status);
        $this->assertSame('منيرة الحربي', $consult->employee);
        $this->assertCount(1, $consult->audit);

        // معالجة الفريق القانوني (قالب احتياطي بلا مفاتيح AI)
        $this->actingAs($employee)->post(route('employee.consults.analyze', $consult))->assertRedirect();
        $consult->refresh();
        $this->assertSame('بانتظار اعتماد الموظف', $consult->status);
        $this->assertTrue($consult->ai_done);
        $this->assertSame('استشارة تجاري', $consult->ai_class);
        $this->assertStringContainsString('نزاع تجاري مع مورّد', $consult->ai_summary);
        $this->assertSame('أ. سارة القحطاني', $consult->ai_lawyer);

        // اعتماد التحليل
        $this->actingAs($employee)->post(route('employee.consults.approve', $consult))->assertRedirect();
        $this->assertSame('جاهزة للمحامي', $consult->fresh()->status);

        // الإحالة للمحامي — يُشعر العميل
        $this->actingAs($employee)->post(route('employee.consults.refer', $consult), [
            'lawyer' => 'أ. خالد المالكي',
        ])->assertRedirect();
        $consult->refresh();
        $this->assertSame('محالة للمحامي', $consult->status);
        $this->assertSame('أ. خالد المالكي', $consult->lawyer);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
    }

    public function test_request_docs_notifies_client(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $consult = $this->makeConsult($client, ['status' => 'قيد مراجعة الموظف']);

        $this->actingAs($employee)->post(route('employee.consults.reqdocs', $consult), [
            'docs' => 'نسخة العقد الموقّعة',
        ])->assertRedirect();

        $consult->refresh();
        $this->assertSame('بانتظار استكمال البيانات', $consult->status);
        $this->assertContains('نسخة العقد الموقّعة', $consult->missing);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
    }

    public function test_save_analysis_logs_audit_diffs(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $consult = $this->makeConsult($client, [
            'status' => 'بانتظار اعتماد الموظف', 'ai_done' => true,
            'ai_class' => 'استشارة تجاري', 'ai_summary' => 'نص', 'ai_lawyer' => 'أ. سارة القحطاني',
        ]);

        $this->actingAs($employee)->post(route('employee.consults.analysis', $consult), [
            'aiClass' => 'استشارة عقود تجارية',
            'aiSummary' => 'نص محدّث',
            'aiLawyer' => 'أ. خالد المالكي',
        ])->assertRedirect();

        $consult->refresh();
        $this->assertSame('استشارة عقود تجارية', $consult->ai_class);
        $this->assertSame('أ. خالد المالكي', $consult->ai_lawyer);
        $this->assertCount(3, $consult->audit); // تصنيف + ملخص + محامٍ
    }

    public function test_admin_updates_priority_and_assigns_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->makeConsult($client, ['priority' => 'متوسطة']);

        $this->actingAs($admin)->post(route('admin.consults.priority', $consult), [
            'priority' => 'عالية',
        ])->assertRedirect();
        $this->assertSame('عالية', $consult->fresh()->priority);

        $this->actingAs($admin)->post(route('admin.consults.refer', $consult), [
            'lawyer' => 'أ. ريم الزهراني',
        ])->assertRedirect();
        $consult->refresh();
        $this->assertSame('محالة للمحامي', $consult->status);
        $this->assertSame('أ. ريم الزهراني', $consult->lawyer);
    }

    public function test_employee_cannot_set_priority_route(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $consult = $this->makeConsult($client);

        // مسار الأولوية مسجّل للإدارة فقط
        $this->actingAs($employee)->post("/employee/consults/{$consult->id}/priority", [
            'priority' => 'عالية',
        ])->assertNotFound();
    }

    public function test_journey_pages_render_with_real_data(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->makeConsult($client);

        $this->actingAs($employee)->get(route('employee.consults'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('employee/consults')->has('consults', 1));

        $this->actingAs($employee)->get('/employee/consult?ref='.$consult->ref)
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('employee/consult')->where('consult.ref', $consult->ref));

        $this->actingAs($admin)->get(route('admin.consults'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/consults')->has('consults', 1));

        $this->actingAs($admin)->get('/admin/consult?ref='.$consult->ref)
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/consult')->where('consult.status', 'جديدة'));
    }
}
