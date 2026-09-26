<?php

namespace Tests\Feature;

use App\Domain\Journey\Transitions\Ticket\CorrectTicketStatus;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AdminDashboardService;
use App\Support\AdminApprovalQueue;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **حرّاس تدقيق لوحة الإدارة (المجموعة أ) — 2026-09-26.**
 *
 * كلّ اختبارٍ هنا يقفل علّةً وُجدت في التدقيق فلا تعود: زرّ تصفيرٍ يظهر حيث يردّه الخادم،
 * رادارٌ يقول «لا طلبات» والمركز مليء، عدّادات بقوائم حالاتٍ مكتوبة، إسنادٌ جماعيّ يُلغي بعضُه
 * بعضاً، نموذج تصحيحٍ يعرض ما يُرفض، وطلب نواقص يُكتب على تذكرةٍ مجمَّدة.
 */
class AdminAuditGroupAGuardsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->client = User::factory()->create(['role' => Role::Client]);
    }

    private function ticket(array $overrides = []): Ticket
    {
        $status = $overrides['status'] ?? 'قيد التحليل';

        return Ticket::create(array_merge([
            'user_id' => $this->client->id,
            'number' => 'SB-GA-'.uniqid(),
            'type' => 'نزاع',
            'status' => $status,
            'tone' => TicketJourney::toneFor($status),
        ], $overrides));
    }

    // ── ١: أداة التصفير حيث يقبلها الخادم وحده ──

    public function test_reset_tool_is_offered_outside_production_only(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertInertia(fn ($p) => $p->where('canReset', true)->missing('stats')->missing('activity'));

        $this->app['env'] = 'production';
        config(['app.allow_db_reset' => false]);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertInertia(fn ($p) => $p->where('canReset', false));
        // بيئة الإنتاج تُفعّل حارس CSRF — تُتخطّى الوسائط ليصل الطلب حارسَ المتحكّم نفسه
        $this->withoutMiddleware()
            ->actingAs($this->admin)->post(route('admin.reset-database'), ['confirm' => 'RESET'])->assertForbidden();
    }

    /** العبارة تُكتب بيد المدير — لا ثابتةً في الشفرة تُرسَل بنقرة. */
    public function test_reset_ui_sends_the_typed_phrase_not_a_constant(): void
    {
        $ui = (string) file_get_contents(resource_path('js/pages/admin/dashboard.tsx'));

        $this->assertStringNotContainsString("{ confirm: 'RESET' }", $ui);
        $this->assertStringContainsString('usePrompt', $ui);
        $this->assertStringContainsString('canReset &&', $ui);
    }

    // ── ٢ و٣: عدّادات اللوحة بنطاقات النموذج، والرادار من تعريف المركز ──

    public function test_dashboard_counts_use_model_scopes_and_radar_covers_every_approval(): void
    {
        $this->ticket(); // مفتوحة غير مسندة
        $this->ticket(['status' => 'محولة إلى قضية']); // ليست مفتوحة (كانت تُعدّ)
        $this->ticket(['is_frozen' => true, 'status' => 'بانتظار اعتماد الإدارة للمسار', 'proposed_track' => 'case']); // مجمَّدة: لا توزيع

        // مقترح مسار · محضر جلسة · موعد بانتظار الاعتماد
        $this->ticket(['status' => 'بانتظار اعتماد الإدارة للمسار', 'proposed_track' => 'close', 'proposed_track_reason' => 'لا سند نظاميّ للدعوى']);
        Consult::create(['user_id' => $this->client->id, 'ref' => 'CN-GA-1', 'subject' => 'استشارة', 'channel' => 'مرئية', 'lawyer' => 'م', 'status' => 'قيد الاستشارة', 'session' => 'منتهية', 'summary' => 'ملخّص', 'summary_lawyer_approved_at' => now()]);
        Consult::create(['user_id' => $this->client->id, 'ref' => 'CN-GA-2', 'subject' => 'استشارة', 'channel' => 'مرئية', 'lawyer' => 'م', 'status' => 'بانتظار اعتماد الموعد']);

        $data = app(AdminDashboardService::class)->get360Data(bypassCache: true);

        $this->assertSame(Ticket::open()->count(), $data['overview']['openTickets']);
        $this->assertSame(3, $data['overview']['openTickets'], 'المحوّلة ليست مفتوحة');

        $radar = collect($data['radar'])->keyBy('id');
        $this->assertSame(2, $radar['unassigned-tickets']['count'], 'المجمَّدة لا تُعدّ بانتظار التوزيع');
        $counts = AdminApprovalQueue::counts();
        $this->assertSame($counts['proposals'], $radar['pending-tracks']['count']);
        $this->assertSame(1, $radar['pending-sessions']['count']);
        $this->assertSame(1, $radar['pending-appointments']['count']);
    }

    // ── ٤: التحصيل الشهريّ بتاريخ السداد ──

    public function test_monthly_collection_is_bucketed_by_paid_at_not_updated_at(): void
    {
        $invoice = Invoice::create([
            'user_id' => $this->client->id, 'number' => 'INV-GA-1', 'description' => 'أتعاب', 'amount' => 5000,
            'status' => 'مدفوعة', 'tone' => 'b-green', 'due_label' => '—', 'paid' => true,
            'paid_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3),
        ]);
        $invoice->touch(); // تعديلٌ لاحق على فاتورةٍ سُدّدت الشهر الماضي — لا ينقلها لهذا الشهر

        $finance = app(AdminDashboardService::class)->get360Data(bypassCache: true)['finance'];

        $this->assertSame(0, $finance['currentMonthCollected']);
        $this->assertSame(5000, $finance['prevMonthCollected']);
    }

    // ── ٦: «بانتظار الاعتماد» في التذاكر يشمل مقترحات المسار ──

    public function test_pending_admin_tab_includes_outcome_track_approvals(): void
    {
        $this->ticket(['status' => 'بانتظار اعتماد الإدارة للمسار', 'proposed_track' => 'case', 'proposed_track_reason' => 'نزاع يستوجب الدعوى']);

        $this->actingAs($this->admin)->get(route('admin.tickets', ['status' => 'pending_admin']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('tickets.meta.total', 1)->where('summaryStats.pending_admin', 1));
    }

    // ── ٩: نموذج التصحيح من الحارس ──

    public function test_correction_form_offers_only_what_the_guard_accepts(): void
    {
        $plain = $this->ticket();
        $form = CorrectTicketStatus::form($plain);
        $values = array_column($form['targets'], 'value');

        $this->assertNull($form['blocker']);
        $this->assertNotContains('قيد التحليل', $values, 'لا تصحيح إلى الحالة الحاليّة');
        $this->assertNotContains('محولة إلى قضية', $values, 'لا حالة تحويلٍ بلا ملفّ');
        $this->assertContains('بانتظار قرار المآل', $values, 'حالتا المآل كانتا ناقصتين من القائمة المكتوبة');

        $converted = $this->ticket(['status' => 'محولة إلى تنفيذ']);
        Execution::create(['user_id' => $this->client->id, 'ticket_id' => $converted->id, 'number' => 'EXE-GA-1', 'subject' => 'سند', 'status' => 'جديد', 'tone' => 'b-blue']);

        $blocked = CorrectTicketStatus::form($converted->fresh());
        $this->assertNotNull($blocked['blocker']);
        $this->assertSame([], $blocked['targets']);

        $this->actingAs($this->admin)->get(route('admin.tickets.show', $converted))
            ->assertInertia(fn ($p) => $p->where('correction.blocker', $blocked['blocker'])->where('ticket.closureReasonCode', null));
    }

    // ── ١٠: طلب النواقص لا يُكتب على تذكرةٍ مجمَّدة ──

    public function test_request_docs_refuses_a_frozen_ticket(): void
    {
        $frozen = $this->ticket(['is_frozen' => true, 'status' => 'محولة إلى قضية']);

        $this->actingAs($this->admin)->post(route('admin.tickets.reqdocs', $frozen))->assertStatus(422);

        $this->assertSame(0, TicketMessage::where('ticket_id', $frozen->id)->count());
    }

    // ── ١٣: الإسناد الجماعيّ طلبٌ واحد يُعلن ما أُسند وما رُفض ──

    public function test_bulk_assign_assigns_each_item_and_reports_refusals(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $open = $this->ticket();
        $frozen = $this->ticket(['is_frozen' => true]);

        $this->actingAs($this->admin)
            ->from(route('admin.distribute'))
            ->post(route('admin.distribute.bulk'), [
                'lawyer_id' => $lawyer->id,
                'items' => [['kind' => 'ticket', 'id' => $open->id], ['kind' => 'ticket', 'id' => $frozen->id]],
            ])
            ->assertRedirect(route('admin.distribute'))
            ->assertSessionHas('flash', fn ($m) => str_contains($m, '1 من 2'))
            ->assertSessionHasErrors('message');

        $this->assertSame($lawyer->id, $open->fresh()->assigned_lawyer_id);
        $this->assertNull($frozen->fresh()->assigned_lawyer_id);
    }

    /** الواجهة ترسل طلباً واحداً — لا حلقة `router.post` يُلغي فيها Inertia بعضَها بعضاً. */
    public function test_distribute_ui_posts_bulk_once(): void
    {
        $ui = (string) file_get_contents(resource_path('js/pages/admin/distribute.tsx'));

        $this->assertStringContainsString("'/admin/distribute/bulk'", $ui);
        $this->assertStringNotContainsString('itemsToAssign.forEach', $ui);
    }

    /** ١٩: «الاستبعاد» بلا سبب أُزيل — لا التفاف على الرفض المسبَّب. */
    public function test_dismiss_without_reason_is_gone(): void
    {
        $this->actingAs($this->admin)->post('/admin/approvals/dismiss', ['type' => 'track', 'ref' => 'X'])->assertNotFound();
    }
}
