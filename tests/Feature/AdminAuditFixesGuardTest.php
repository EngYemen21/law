<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Enums\HearingStatus;
use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Enums\MeetingStatus;
use App\Enums\Role;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Finance\RevenueSnapshot;
use App\Support\Reports\PerformanceSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **حرّاس تدقيق لوحة الإدارة (2026-09-26)** — كلّ اختبارٍ هنا يُسقط الحزمة إن عاد عطلٌ أُصلح من مصدره:
 * أعلامٌ يحسبها الخادم بدل مقارنة نصوصٍ عربيّة في الواجهة، ومصدرٌ واحد لكلّ رقم، ولا مسارٌ يتخطّى السبب.
 */
class AdminAuditFixesGuardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    // ───────────────────────────── الاجتماعات ─────────────────────────────

    public function test_every_office_role_has_the_meeting_stream_route(): void
    {
        // `recording-ui.tsx` يبني `${base}/meetings/{id}/stream/*` لكلّ دور — غيابه في الإدارة كان ٤٠٤
        foreach (['admin', 'lawyer', 'employee'] as $prefix) {
            $this->assertTrue(Route::has("{$prefix}.meetings.stream"), "مسار التشغيل غائب عن {$prefix}");
        }
    }

    public function test_placeholder_summary_is_not_approvable_and_the_card_says_so(): void
    {
        $admin = $this->admin();
        $placeholder = Meeting::create([
            'ref' => 'M-9001', 'title' => 'قالبيّ', 'when_label' => 'أمس',
            'status' => MeetingStatus::Ended->value, 'summary' => 'بانتظار ملخص الجلسة من Zoom',
        ]);
        $real = Meeting::create([
            'ref' => 'M-9002', 'title' => 'حقيقيّ', 'when_label' => 'أمس',
            'status' => MeetingStatus::Ended->value, 'minutes' => 'محضرٌ مدوَّن فعلاً',
        ]);

        // البطاقة تحمل حكم الحارس نفسه — والزرّ في الواجهة يُشرط به
        $this->assertFalse($placeholder->toFullCard()['canApprove']);
        $this->assertTrue($real->toFullCard()['canApprove']);
        $this->assertSame('ended', $real->toFullCard()['statusKey']);
        $this->assertFalse($real->toFullCard()['approved']);

        // والخادم يرفض ما لا تعرضه البطاقة
        $this->actingAs($admin)->post(route('admin.meetings.approve', $placeholder))->assertStatus(422);
        $this->actingAs($admin)->post(route('admin.meetings.approve', $real))->assertRedirect();
        $this->assertTrue($real->fresh()->toFullCard()['approved']);
        $this->assertFalse($real->fresh()->toFullCard()['canApprove']);
    }

    public function test_meeting_forms_list_active_lawyers_only_and_arabic_roles(): void
    {
        $admin = $this->admin();
        $active = User::factory()->create(['role' => Role::Lawyer, 'name' => 'محامٍ نشط', 'status' => 'active']);
        User::factory()->create(['role' => Role::Lawyer, 'name' => 'محامٍ موقوف', 'status' => 'suspended']);

        $this->actingAs($admin)->get('/admin/meetmgmt')->assertOk()
            ->assertInertia(fn ($p) => $p->where('lawyers', fn ($l) => collect($l)->pluck('name')->all() === ['محامٍ نشط'])
                ->where('staff', fn ($s) => collect($s)->every(fn ($row) => preg_match('/^[a-z]+$/', (string) $row['role']) !== 1)));

        $this->actingAs($admin)->get('/admin/meetreqs')->assertOk()
            ->assertInertia(fn ($p) => $p->where('lawyers', fn ($l) => collect($l)->pluck('id')->all() === [$active->id]));
    }

    public function test_meet_request_rejects_a_suspended_lawyer(): void
    {
        $admin = $this->admin();
        $client = User::factory()->create(['role' => Role::Client]);
        $suspended = User::factory()->create(['role' => Role::Lawyer, 'status' => 'suspended']);

        $this->actingAs($admin)->post(route('admin.meetreqs.store'), [
            'client_id' => $client->id, 'lawyer_id' => $suspended->id, 'type' => 'استشارة مرئية',
            'day' => now()->addDays(2)->format('Y-m-d'), 'time' => '10:00',
        ])->assertSessionHasErrors('lawyer_id');
    }

    // ───────────────────────────── المالية والإيرادات ─────────────────────────────

    public function test_paying_a_paid_invoice_is_a_validation_error_not_a_flash(): void
    {
        $admin = $this->admin();
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-G1', 'description' => 'أتعاب', 'amount' => 1000,
            'status' => InvoiceStatus::Paid->value, 'tone' => 'b-green', 'due_label' => '—', 'paid' => true,
        ]);

        // `back()->with('error')` كان تحويلاً ناجحاً فتُطلق الواجهة «تم التحصيل» مع رسالة الرفض
        $this->actingAs($admin)->from('/admin/finance')->post(route('admin.invoices.pay', $invoice))
            ->assertSessionHasErrors('invoice')
            ->assertSessionMissing('error');
    }

    public function test_invoice_collection_rate_does_not_count_written_off_as_collected(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $base = ['user_id' => $client->id, 'description' => 'أتعاب', 'tone' => 'b-grey', 'due_label' => '—'];
        Invoice::create($base + ['number' => 'INV-R1', 'amount' => 1000, 'status' => InvoiceStatus::Paid->value, 'paid' => true]);
        Invoice::create($base + ['number' => 'INV-R2', 'amount' => 1000, 'status' => InvoiceStatus::WrittenOff->value, 'paid' => false]);

        // المحصَّل ÷ الصادر = 1000 ÷ 2000 — الاشتقاق القديم (الصادر − الذمم) كان يعطي 100٪
        $this->assertSame(50, RevenueSnapshot::build()->invoiceCollectionRate);
    }

    // ───────────────────────────── التقارير ─────────────────────────────

    public function test_performance_snapshot_buckets_by_effective_stage_without_double_counting(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $mk = fn (string $no, ?int $stage, string $status) => Execution::create([
            'user_id' => $client->id, 'number' => $no, 'subject' => 'سند', 'stage' => $stage, 'status' => $status,
        ]);
        $mk('EX-A', null, ExecutionStatus::InProgress->value);   // قديمٌ بلا مرحلة ⇒ قيد المحكمة (8)
        $mk('EX-B', 3, ExecutionStatus::Closed->value);          // مغلقٌ بحالته ومرحلته 3 ⇒ «مغلق» وحده
        $mk('EX-C', 7, ExecutionStatus::InProgress->value);

        $buckets = collect(PerformanceSnapshot::build()->executionsByStage)->pluck('v', 'm');
        $this->assertSame(0, $buckets['دراسة وأتعاب']);
        $this->assertSame(1, $buckets['بانتظار ناجز']);
        $this->assertSame(1, $buckets['قيد إجراءات المحكمة']);
        $this->assertSame(1, $buckets['مكتمل ومغلق']);
        // مجموع الشرائح = عدد الملفّات — لا عدٌّ مزدوج ولا ساقط
        $this->assertSame(3, $buckets->sum());
    }

    public function test_active_cases_follow_case_journey_and_audit_rows_are_arabic(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        LegalCase::create(['user_id' => $client->id, 'number' => 'C-1', 'type' => 'تجاري', 'status' => CaseStatus::AwaitingRegistration->value]);
        LegalCase::create(['user_id' => $client->id, 'number' => 'C-2', 'type' => 'تجاري', 'status' => CaseStatus::InCourt->value]);
        JourneyTransition::create([
            'entity_type' => LegalCase::class, 'entity_id' => 1, 'entity_ref' => 'C-1',
            'transition' => 'ticket.convert_to_case', 'from_state' => 'أ', 'to_state' => 'ب',
        ]);

        // «بانتظار القيد» نشطةٌ في `CaseJourney::ACTIVE` — كانت الشاشة والتقرير يُسقطانها
        $this->assertSame(2, PerformanceSnapshot::build()->stats['activeCases']);

        $row = PerformanceSnapshot::recentTransitions()[0];
        $this->assertSame('قضية', $row['type']);
        $this->assertSame('تحويل التذكرة إلى قضية', $row['transition']);
    }

    // ───────────────────────────── القضايا والأتعاب ─────────────────────────────

    public function test_closing_a_case_requires_a_catalogue_reason(): void
    {
        $admin = $this->admin();
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create(['user_id' => $client->id, 'number' => 'C-CL', 'type' => 'تجاري', 'status' => CaseStatus::Judged->value]);

        // `{}` كان يُسجَّل «صدور حكم نهائي» — الآن يُردّ ولا تُغلق القضية
        $this->actingAs($admin)->post(route('admin.cases.close', $case))->assertSessionHasErrors('closure_reason');
        $this->assertSame(CaseStatus::Judged->value, $case->fresh()->status);

        $this->actingAs($admin)->post(route('admin.cases.close', $case), ['closure_reason' => 'AMICABLE_SETTLEMENT'])->assertRedirect();
        $this->assertSame('AMICABLE_SETTLEMENT', $case->fresh()->closure_reason);

        // صفحة التفاصيل: إعادة الفتح بعلَم الخادم، والأسباب من الكتالوج
        $this->actingAs($admin)->get(route('admin.cases.show', $case))
            ->assertInertia(fn ($p) => $p->where('case.canReopen', true)->has('closureReasons', 7));
    }

    public function test_case_fee_rows_carry_can_set_fee_and_installments(): void
    {
        $admin = $this->admin();
        $client = User::factory()->create(['role' => Role::Client]);
        LegalCase::create(['user_id' => $client->id, 'number' => 'C-F1', 'type' => 'تجاري', 'status' => CaseStatus::AwaitingFeeApproval->value, 'fee_status' => 'none']);
        LegalCase::create([
            'user_id' => $client->id, 'number' => 'C-F2', 'type' => 'تجاري', 'status' => CaseStatus::InPreparation->value,
            'fee_status' => 'installments', 'fee' => 3000, 'installments_total' => 3, 'installments_paid' => 1,
        ]);

        $this->actingAs($admin)->get('/admin/casefees')->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('cases.data', fn ($rows) => collect($rows)->firstWhere('no', 'C-F1')['canSetFee'] === true
                    && collect($rows)->firstWhere('no', 'C-F2')['canSetFee'] === false
                    && collect($rows)->firstWhere('no', 'C-F2')['installmentsPaid'] === 1));
    }

    // ───────────────────────────── التنفيذ ─────────────────────────────

    public function test_exec_flow_card_carries_rejection_and_reprice_flags(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $rejected = Execution::create(['user_id' => $client->id, 'number' => 'EX-R', 'subject' => 'سند', 'stage' => 2, 'status' => ExecutionStatus::InProgress->value, 'decision' => 'مرفوض']);
        $offer = Execution::create(['user_id' => $client->id, 'number' => 'EX-O', 'subject' => 'سند', 'stage' => 5, 'status' => ExecutionStatus::InProgress->value, 'offer_status' => 'مرفوض']);

        $r = $rejected->toFlowCard(internal: true);
        $this->assertTrue($r['isRejected']);
        $this->assertTrue($r['rejectedOpen']);
        $this->assertFalse($r['canReprice'], 'المرفوض بعد الدراسة لا يُسعَّر');

        $o = $offer->toFlowCard(internal: true);
        $this->assertTrue($o['offerRejected']);
        $this->assertTrue($o['rejectedOpen']);
        $this->assertTrue($o['canReprice'], 'عرضٌ رفضه العميل يُعاد تسعيره');
    }

    // ───────────────────────────── الجلسات والأرشيف ─────────────────────────────

    public function test_hearings_csv_puts_the_opponent_under_opponent_and_adds_duration(): void
    {
        $admin = $this->admin();
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create(['user_id' => $client->id, 'number' => 'T-OPP', 'type' => 'تجاري', 'department' => 'القسم التجاري', 'status' => 'محولة إلى قضية', 'opponent_name' => 'شركة الخصم']);
        $case = LegalCase::create(['user_id' => $client->id, 'ticket_id' => $ticket->id, 'number' => 'C-H1', 'type' => 'تجاري', 'status' => CaseStatus::InCourt->value, 'department' => 'القسم التجاري']);
        CaseHearing::create([
            'case_id' => $case->id, 'title' => 'جلسة', 'day' => now()->addDay()->toDateString(), 'time' => '10:00',
            'starts_at' => now()->addDay(), 'status' => HearingStatus::Scheduled->value, 'duration_min' => 45,
        ]);

        $res = $this->actingAs($admin)->get('/admin/hearings/export');
        ob_start();
        $res->sendContent();
        $csv = (string) ob_get_clean();
        $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv))))));
        $row = array_combine($rows[0], $rows[1]);

        $this->assertSame('شركة الخصم', $row['الخصم']);
        $this->assertSame('45', $row['المدّة المتوقّعة (دقائق)']);

        $this->actingAs($admin)->get('/admin/hearings')->assertInertia(fn ($p) => $p
            ->where('hearings.data.0.durationMin', 45)
            ->where('hearings.data.0.tone', 'b-blue')
            ->has('hearings.data.0.isToday'));
    }

    public function test_archive_sends_measured_duration_not_the_stale_label(): void
    {
        $admin = $this->admin();
        $client = User::factory()->create(['role' => Role::Client]);
        Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-DUR', 'subject' => 'نزاع', 'type' => 'استشارة', 'channel' => 'مرئية',
            'status' => 'منتهية', 'session' => 'منتهية', 'tone' => 'b-green', 'lawyer' => 'مستشار',
            'duration_sec' => 1500, 'duration_label' => '60 دقيقة',
        ]);

        $this->actingAs($admin)->get('/admin/archive')->assertInertia(fn ($p) => $p
            ->where('rows.0.durationSec', 1500)
            ->missing('rows.0.dur'));
    }

    // ───────────────────────────── حارس المسح ─────────────────────────────

    /**
     * **لا عودة للمقارنات النصّيّة ولا للبيانات التجريبيّة** في شاشات الإدارة المُصلَحة: المنطق يشرط
     * بأعلام الخادم (`statusKey`/`approved`/`canApprove`/`canReopen`/`canSetFee`/`isRejected`…).
     */
    public function test_fixed_admin_screens_do_not_compare_arabic_statuses_or_use_demo_data(): void
    {
        $files = [
            'resources/js/pages/admin/meetings.tsx',
            'resources/js/pages/admin/meetmgmt.tsx',
            'resources/js/pages/admin/meetlog.tsx',
            'resources/js/pages/admin/meetreports.tsx',
            'resources/js/pages/admin/case.tsx',
            'resources/js/pages/admin/casefees.tsx',
            'resources/js/pages/admin/hearings.tsx',
            'resources/js/pages/admin/revenue.tsx',
            'resources/js/pages/admin/archive.tsx',
        ];
        $forbidden = [
            "=== 'منتهٍ'", "!== 'منتهٍ'", "=== 'معتمد'", "!== 'معتمد'", "=== 'لم ينعقد'", "=== 'قادم'",
            "=== 'مغلقة'", "=== 'بانتظار اعتماد الأتعاب'", "case 'منعقدة'", "=== 'اليوم'",
            'STAFF_DIR', 'PAY_METHODS', 'maskClient', 'a.dur}',
        ];

        foreach ($files as $file) {
            $src = (string) file_get_contents(base_path($file));
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $src, "{$file} يحوي «{$needle}» — استعمل علَم الخادم");
            }
        }

        $meetingUi = (string) file_get_contents(base_path('resources/js/lib/meeting-ui.tsx'));
        foreach (["status === 'منتهٍ'", "=== 'معتمد'", "status === 'لم ينعقد'"] as $needle) {
            $this->assertStringNotContainsString($needle, $meetingUi);
        }

        $exec = (string) file_get_contents(base_path('resources/js/pages/execflow.tsx'));
        $this->assertStringNotContainsString("r.decision === 'مرفوض'", $exec);
        $this->assertStringNotContainsString("r.decision !== 'مرفوض'", $exec);
    }
}
