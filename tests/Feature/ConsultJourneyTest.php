<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * تحقّق من رحلة معالجة الاستشارة (CONSULT_FLOW):
 * استقبال → مراجعة الموظف → معالجة الفريق القانوني → اعتماد الموظف → إحالة للمحامي.
 */
class ConsultJourneyTest extends TestCase
{
    use BuildsConsultJourney;
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
        $ticket = $this->ticketWithApprovedOpinion($client, [
            'number' => 'SB-2026-8888',
            'department' => 'القسم التجاري',
            'subject' => null,
            'status' => 'بانتظار حجز الاستشارة',
        ]);

        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);

        // الدورة الكاملة: طلب → تسعير الإدارة → سداد → الإدارة تحدّد الموعد → دخول الرحلة «جديدة»
        $consult = $this->requestPricedAndPaid($client, $ticket, 'video');
        $this->adminPublishes($consult, [
            'lawyer_id' => $lawyer->id, 'date' => now()->addDays(2)->toDateString(), 'time' => '11:30',
        ])->assertOk();

        $consult->refresh();
        $this->assertSame('جديدة', $consult->status);
        $this->assertSame('التجاري', $consult->type);
        $this->assertSame('متوسطة', $consult->priority);
        $this->assertNotEmpty($consult->audit);
    }

    public function test_full_journey_analyze_approve_refer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee, 'name' => 'منيرة الحربي']);
        User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سارة القحطاني']);
        $consult = $this->makeConsult($client);

        // لا «استلام»: أُزيل الزرّ ومساره بقرار المالك وطُويت حالته — المعالجة تبدأ من «جديدة»
        $this->assertSame('جديدة', $consult->status);

        // معالجة الفريق القانوني بلا مفاتيح AI ⇒ القالب الاحتياطيّ.
        // الاستشارة تنتقل إلى «بانتظار اعتماد الموظف» كما كانت (إنسانٌ يجب أن يتصرّف)،
        // لكنها لا تُوسَم تحليلاً مكتملاً ولا تحمل محامياً «مقترحاً» بلا تحليل خلفه.
        $this->actingAs($employee)->post(route('employee.consults.analyze', $consult))->assertRedirect();
        $consult->refresh();
        $this->assertSame('بانتظار اعتماد الموظف', $consult->status);
        $this->assertFalse((bool) $consult->ai_done);
        $this->assertSame(AiSource::Fallback->value, $consult->ai_source);
        $this->assertSame('استشارة تجاري', $consult->ai_class);
        $this->assertStringContainsString('نزاع تجاري مع مورّد', $consult->ai_summary);
        $this->assertSame('', $consult->ai_lawyer, 'لا ترشيح محامٍ بلا تحليل — كان يقع على أوّل محامٍ أبجديّاً');

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
        $consult = $this->makeConsult($client, ['status' => 'جديدة']);

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

    /**
     * **لا تُحال استشارةٌ ما زالت في دورة الحجز** — وإلّا سقطت من طابور التسعير.
     *
     * للاستشارة دورتان: حجزٌ (تسعير ← سداد ← موعد) وتحليلٌ ينتهي بالإحالة. وكان
     * `refer` بلا شرطٍ واحد، فإحالةٌ على طلبٍ في الأولى تنقله إلى «محالة للمحامي»
     * وهي خارج `PRE_SESSION_STATUSES` التي يُبنى منها طابور التسعير. فلا يُسعَّر،
     * ولا تصل الفاتورة، ولا يدفع العميل، ولا يختار موعداً — وينتظر بلا أن يعلم.
     *
     * وقع في `CN-2026-4504`: أُحيلت وهي «بانتظار التسعير»، فظهرت في شاشة استقبال
     * الجلسات بموعدٍ فارغ وزرِّ «بدء الجلسة» مُفعَّلاً.
     */
    public function test_a_consult_still_in_the_booking_cycle_cannot_be_referred(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        foreach (Consult::PRE_SESSION_STATUSES as $status) {
            $consult = Consult::create([
                'user_id' => $client->id, 'ref' => 'CN-REF-'.uniqid(), 'subject' => 'استشارة',
                'type' => 'استشارة', 'channel' => 'مرئية', 'status' => $status,
                'tone' => 'b-amber', 'lawyer' => 'مستشار المكتب',
            ]);

            $this->actingAs($admin)
                ->post("/admin/consults/{$consult->id}/refer", ['lawyer_id' => $lawyer->id])
                ->assertStatus(422);

            $this->assertSame($status, $consult->fresh()->status, "«{$status}» تبقى في طابورها");
            $this->assertContains(
                $consult->fresh()->status,
                Consult::PRE_SESSION_STATUSES,
                'ولا تسقط من قائمة التسعير لدى الإدارة'
            );
        }
    }

    /** ومَن أكمل الحجز تُحال كالمعتاد — المنع مشروطٌ لا دائم. */
    public function test_a_booked_consult_is_still_referable(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-OK-'.uniqid(), 'subject' => 'استشارة',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'جاهزة للمحامي',
            'tone' => 'b-green', 'lawyer' => 'مستشار المكتب',
            'priced_at' => now(), 'paid_at' => now(), 'starts_at' => now()->addDay(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/refer", ['lawyer_id' => $lawyer->id])
            ->assertRedirect();

        $this->assertSame('محالة للمحامي', $consult->fresh()->status);
    }

    /** والإشعار لا يَعِد بموعدٍ لم يُحجز. */
    public function test_the_referral_notice_promises_no_unscheduled_appointment(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-NOAPPT-'.uniqid(), 'subject' => 'استشارة',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'جاهزة للمحامي',
            'tone' => 'b-green', 'lawyer' => 'مستشار المكتب',
        ]);

        $this->actingAs($admin)->post("/admin/consults/{$consult->id}/refer", ['lawyer_id' => $lawyer->id]);

        $body = UserNotification::where('user_id', $client->id)->latest('id')->first()?->body ?? '';
        $this->assertStringNotContainsString('في موعدها', $body, 'لا موعد يُوعَد به');
        $this->assertStringContainsString('سنوافيك بموعد الجلسة', $body);
    }
}
