<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ClosureReasonCode;
use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\CaseConversion;
use App\Support\CaseFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * تحقّق من تحويل التذكرة المكتملة إلى قضية: المستشار يحوّل → الإدارة تحدّد الأتعاب → العميل يسدّد فتُفعّل.
 */
class CaseConversionTest extends TestCase
{
    use RefreshDatabase;

    private function completedTicket(User $client, ?User $lawyer = null): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-9100',
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'assigned_lawyer' => $lawyer?->name ?? 'أ. سارة القحطاني',
            'assigned_lawyer_id' => $lawyer?->id,
            'status' => 'مكتملة',
            'tone' => 'b-green',
        ]);
    }

    public function test_lawyer_converts_completed_ticket_to_case(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سارة القحطاني']);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->actingAs($lawyer)->post(route('lawyer.tickets.track.propose', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'رفع مقترح تحويل النزاع التجاري إلى قضية أمام المحكمة المختصة.',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'اعتماد الإدارة العليا لمسار القضية وإحالتها للفريق القضائي.',
        ])->assertRedirect();

        $case = LegalCase::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($case);
        $this->assertSame($client->id, $case->user_id);
        $this->assertSame('نزاع تجاري', $case->type);
        $this->assertSame('بانتظار اعتماد الأتعاب', $case->status);
        $this->assertMatchesRegularExpression('/^CASE-\d{4}-\d{4}$/', $case->number);

        // إشعار للعميل + رسالة في محادثة التذكرة + ظهور القضية لدى العميل
        $this->assertTrue(UserNotification::where('user_id', $client->id)->exists());
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->role === 'اعتماد المسار' || $m->role === 'تحويل لقضية'));
        $this->actingAs($client)->get(route('cases'))
            ->assertOk()->assertInertia(fn ($p) => $p->has('cases', 1));
    }

    public function test_employee_converts_completed_ticket_to_case(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        // محامٍ نشط في التوزيع التلقائي: التحويل لم يعد يُنتج قضية بلا محامٍ حقيقي
        // (اسم نصّي بلا معرّف كان يُخرج القضية من قائمة كل محامٍ — CaseLawyerResolutionTest)
        User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'distribution_mode' => 'auto']);
        $ticket = $this->completedTicket($client);
        // في التدفق الواقعي لا تصل التذكرة «مكتملة» إلا بعد اعتماد المستشار للملخّص — شرط تحويل الموظف
        TicketSummary::create([
            'ticket_id' => $ticket->id, 'case_summary' => 'ملخّص معتمد', 'status' => 'approved',
            'approved_at' => now(), 'ai_generated' => true,
        ]);

        $this->actingAs($employee)->post(route('employee.tickets.track.propose', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'مقترح الموظف بإحالة الطلب إلى مسار قضية تجارية.',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'اعتماد الإدارة العليا لمسار القضية بناء على مقترح الموظف.',
        ])->assertRedirect();

        $case = LegalCase::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($case);
        $this->assertSame('بانتظار اعتماد الأتعاب', $case->status);
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->role === 'اعتماد المسار' || $m->role === 'تحويل لقضية'));
    }

    /** المسار المباشر القديم للموظف محذوف نهائياً — الحوكمة الرسمية تقبل المقترح من أي حالة (ADR-009). */
    public function test_employee_direct_convert_route_is_fully_removed(): void
    {
        $this->assertFalse(
            Route::has('employee.tickets.convert'),
            'مسار employee.tickets.convert لا يزال مسجلاً رغم وجوب حذفه بموجب ADR-009.'
        );
    }

    /** المسار المباشر القديم للمحامي محذوف نهائياً — الحوكمة الرسمية تقبل المقترح (ADR-009). */
    public function test_lawyer_direct_convert_route_is_fully_removed(): void
    {
        $this->assertFalse(
            Route::has('lawyer.tickets.convert'),
            'مسار lawyer.tickets.convert لا يزال مسجلاً رغم وجوب حذفه بموجب ADR-009.'
        );
    }

    /**
     * 🔴 الحارس في المتحكّمين كان فحص-ثمّ-تصرّف بلا قفل، و`cases.ticket_id` بلا فهرس فريد.
     * ثلاثة مسارات تشير إلى الإجراء (موظف · محامٍ · إدارة)، فنقرتان متزامنتان تُنشئان
     * قضيّتين لتذكرة واحدة: شاشات الطاقم تُظهر واحدة (hasOne) وقائمة العميل تُظهر الاثنتين.
     */
    public function test_service_refuses_a_second_conversion_for_the_same_ticket(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket($client, $lawyer);

        CaseConversion::fromTicket($ticket, $lawyer);

        // النداء الثاني يتخطّى حارس المتحكّم عمداً — هذا ما يفعله السباق
        try {
            CaseConversion::fromTicket($ticket->fresh(), $lawyer);
            $this->fail('التحويل الثاني نجح — أُنشئت قضية ثانية لنفس التذكرة.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ticket', $e->errors());
        }

        $this->assertSame(1, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    /**
     * قاعدة الاعتماد الثلاثية — **مقصودة لا متناقضة**، وتُثبَّت هنا كي لا «تُصحَّح» سهواً:
     *
     *   • المحامي هو **المعتمِد** للنتيجة، فاشتراط اعتماد سابق عليه دور — يحوّل بلا ملخّص.
     *   • الموظف ينتظر اعتماد المحامي — لا يحوّل قبله.
     *   • الإدارة العليا هي **الاعتماد النهائي** — تحوّل بلا قيد.
     */
    public function test_lawyer_may_convert_without_an_approved_summary(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->assertNull($ticket->summary, 'التذكرة لها ملخّص — الاختبار يفقد معناه.');

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'اعتماد الإدارة العليا لمسار القضية دون اشتراط ملخص مسبق.',
        ])->assertRedirect();

        $this->assertSame(1, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    /** الإدارة العليا: الاعتماد النهائي — تحوّل بلا ملخّص وبلا إسناد. */
    public function test_admin_may_convert_without_an_approved_summary(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        // محامٍ نشط يلتقطه TicketAssignment::pickLawyer — موضوع الاختبار تجاوزُ الإدارة
        // لشرط اعتماد الملخّص، لا إنشاء قضية بلا محامٍ (ذاك يُرفض الآن عمداً)
        User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'distribution_mode' => 'auto']);
        $ticket = $this->completedTicket($client); // بلا إسناد مباشر — يُحلّ تلقائياً
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'اعتماد الإدارة العليا النهائي لمسار القضية.',
        ])->assertRedirect();

        $this->assertSame(1, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    /** المسار المباشر للموظف محذوف — واقتراح المسار عبر الحوكمة يمرّ بنجاح ويحتاج اعتماد الإدارة. */
    public function test_employee_propose_track_requires_admin_approval(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket($client, $lawyer);
        $employee = User::factory()->create(['role' => Role::Employee]);

        // المسار المباشر القديم محذوف
        $this->assertFalse(Route::has('employee.tickets.convert'));

        // المسار الحوكمي الرسمي يقبل المقترح ويحول التذكرة لحالة «بانتظار اعتماد الإدارة»
        $this->actingAs($employee)
            ->post(route('employee.tickets.track.propose', $ticket), [
                'track' => TicketOutcomeTrack::Case->value,
                'reason' => 'مقترح موظف مع تسبيب كافٍ — بانتظار اعتماد الإدارة العليا.',
            ])
            ->assertRedirect();

        // لم تُنشأ قضية بعد — الاقتراح بانتظار اعتماد الإدارة
        $this->assertSame(0, LegalCase::where('ticket_id', $ticket->id)->count());
        $this->assertSame('بانتظار اعتماد الإدارة للمسار', $ticket->fresh()->status);
    }

    /** ومحامٍ غير مسنَد لا يحوّل تذكرة زميله — العزل بالإسناد قائم. */
    public function test_unassigned_lawyer_cannot_convert(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $owner = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket($client, $owner);
        $outsider = User::factory()->create(['role' => Role::Lawyer]);

        $this->actingAs($outsider)
            ->post(route('lawyer.tickets.track.propose', $ticket), [
                'track' => TicketOutcomeTrack::Case->value,
                'reason' => 'محاولة رفع مقترح من محامٍ غير مسند للطلب.',
            ])
            ->assertForbidden();

        $this->assertSame(0, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    public function test_cannot_convert_twice(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'اعتماد مسار القضية للمرة الأولى.',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'محاولة اعتماد المسار مرة ثانية.',
        ])->assertStatus(422);

        $this->assertSame(1, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    public function test_admin_sets_fee_then_client_pays_to_activate(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'تحويل التذكرة إلى قضية لاعتماد أتعابها.',
        ])->assertRedirect();
        $case = LegalCase::where('ticket_id', $ticket->id)->firstOrFail();

        // الإدارة تحدّد الأتعاب
        $this->actingAs($admin)->post(route('admin.cases.fee', $case), ['fee' => 10000])->assertRedirect();
        $case->refresh();
        $this->assertSame('بانتظار سداد الأتعاب', $case->status);
        $this->assertSame('pending_payment', $case->fee_status);
        $this->assertSame(10000, $case->fee);

        // العميل يسدّد → القضية تُفعّل وتدخل التحضير
        CaseFee::markPaid($case->fresh());
        $case->refresh();
        $this->assertSame('قيد التحضير', $case->status);
        $this->assertSame('paid', $case->fee_status);
        $this->assertTrue($case->messages->contains(fn ($m) => $m->role === 'خطة العمل'));
    }

    public function test_admin_sets_lawyer_fee_percentage(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'تحويل التذكرة إلى قضية لضبط نسبة أتعاب المحامي.',
        ])->assertRedirect();
        $case = LegalCase::where('ticket_id', $ticket->id)->firstOrFail();

        $this->actingAs($admin)->post(route('admin.cases.fee', $case), ['fee' => 10000, 'lawyer_pct' => 20])->assertRedirect();
        $case->refresh();
        $this->assertSame(20, $case->lawyer_pct);
        $this->assertSame(2000, $case->lawyer_fee);
    }

    public function test_conversion_runs_ai_analysis(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'تحويل التذكرة إلى قضية وتشغيل التحليل الذكي التلقائي.',
        ])->assertRedirect();
        $case = LegalCase::where('ticket_id', $ticket->id)->firstOrFail();
        // رسالة التحليل الذكي (cfAnalysis) موجودة + النوع/القسم مُعبّآن
        $this->assertTrue($case->messages->contains(fn ($m) => $m->role === 'تحليل'));
        $this->assertNotEmpty($case->type);
        $this->assertNotEmpty($case->department);
    }

    public function test_setfee_creates_real_invoice(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'تحويل التذكرة إلى قضية لإنشاء الفاتورة المالية.',
        ])->assertRedirect();
        $case = LegalCase::where('ticket_id', $ticket->id)->firstOrFail();

        $this->actingAs($admin)->post(route('admin.cases.fee', $case), ['fee' => 10000])->assertRedirect();
        $inv = Invoice::where('case_id', $case->id)->first();
        $this->assertNotNull($inv);
        $this->assertSame(11500, $inv->amount); // 10000 + 15% ضريبة
        $this->assertFalse($inv->paid);

        // سداد كامل → الفاتورة مدفوعة
        CaseFee::markPaid($case->fresh());
        $this->assertTrue($inv->fresh()->paid);
        $this->assertSame('قيد التحضير', $case->fresh()->status);
    }

    public function test_installment_payment_plan(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-2026-0500', 'type' => 'تجاري',
            'status' => 'بانتظار سداد الأتعاب', 'tone' => 'b-amber',
            'fee' => 9000, 'fee_status' => 'pending_payment', 'pleading_status' => 'none',
        ]);

        // كان هذا الاختبار يثبّت السلوك القديم: نقرة «تقسيط» تُفعّل القضية وتحتسب دفعة
        // بلا سداد، ونقرتان تُنهيان الأتعاب. صار التفعيل والتقدّم بالتسوية الفعلية وحدها.
        config(['services.moyasar.secret_key' => 'sk_test_x']);
        Http::fake([
            'api.moyasar.com/*' => Http::response(['id' => 'inv_gw', 'url' => 'https://moyasar.test/pay'], 201),
        ]);
        Invoice::create([
            'user_id' => $client->id, 'case_id' => $case->id, 'number' => 'INV-CONV-1',
            'description' => 'أتعاب', 'amount' => 9000, 'status' => 'مستحقة', 'tone' => 'b-amber',
            'due_label' => '—', 'paid' => false,
        ]);

        // اختيار الخطّة: فواتير حقيقية وحالة أقساط — بلا تفعيل وبلا دفعة محتسبة
        $this->actingAs($client)->post(route('cases.pay', $case), ['plan' => 'install']);
        $case->refresh();
        $this->assertSame('installments', $case->fee_status);
        $this->assertSame(0, $case->installments_paid);
        $this->assertSame('بانتظار سداد الأتعاب', $case->status);
        $this->assertSame(3, Invoice::where('case_id', $case->id)->count());
    }

    public function test_lawyer_closes_ticket_without_case(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->actingAs($lawyer)->post(route('lawyer.tickets.track.propose', $ticket), [
            'track' => TicketOutcomeTrack::Close->value,
            'reason' => 'اكتفاء المستفيد بما ورد في الرأي القانوني ولا داعي للتصعيد.',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Close->value,
            'closure_reason_code' => ClosureReasonCode::OpinionSatisfied->value,
            'reason' => 'اعتماد حفظ التذكرة دون قضية لاكتفاء المستفيد بالرأي.',
        ])->assertRedirect();

        $this->assertSame('مغلقة', $ticket->fresh()->status);
        $this->assertSame(0, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    public function test_lawyer_requests_additional_documents(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->actingAs($lawyer)->post(route('lawyer.tickets.reqdocs', $ticket))->assertRedirect();
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->who === 'lawyer' && $m->role === 'نواقص'));
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
        $this->assertSame('مكتملة', $ticket->fresh()->status); // الحالة لا تتغيّر
    }

    public function test_admin_oversees_case_fees(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'تحويل التذكرة لقضية لمتابعة الإدارة للأتعاب.',
        ])->assertRedirect();

        $this->actingAs($admin)->get(route('admin.casefees'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('admin/casefees')->has('cases.data', 1));
    }
}
