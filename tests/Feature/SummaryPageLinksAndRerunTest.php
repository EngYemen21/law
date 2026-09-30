<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **عيوبٌ ثبتت في اختبار المتصفّح الحقيقيّ (2026-09-30)** في صفحة ملخّص التذكرة وبطاقة المسار:
 *
 * - T1: «رجوع للمركز» عند المحامي يفتح `/lawyer/approvals` ولا مسار له (404).
 * - T2: زرّا «إعادة التحليل الذكي» في الصفحة لا يظهران أبداً — `showSummary` لا يمرّر `canRerunSummary`.
 * - T3: «عرض ملف القضية/التنفيذ» في بطاقة المسار يفتح القائمة لا الملفّ.
 */
class SummaryPageLinksAndRerunTest extends TestCase
{
    use RefreshDatabase;

    private User $lawyer;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $this->lawyer->syncPermissions(Permission::whereIn('name', ['اعتماد الملخصات'])->get());
        $client = User::factory()->create(['role' => Role::Client]);
        $this->ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-SPL-'.uniqid(), 'type' => 'نزاع تجاري',
            'assigned_lawyer_id' => $this->lawyer->id, 'status' => 'بانتظار اعتماد المستشار', 'tone' => 'b-amber',
        ]);
        TicketSummary::create([
            'ticket_id' => $this->ticket->id, 'lawyer_id' => $this->lawyer->id,
            'case_summary' => 'ملخّص', 'attachments_summary' => 'مرفقات', 'facts' => 'وقائع', 'key_points' => 'نقاط',
            'status' => 'awaiting_lawyer', 'ai_generated' => true,
        ]);
    }

    private function rerunFlag(User $as, string $prefix): bool
    {
        return (bool) $this->actingAs($as)->get("/{$prefix}/summary/{$this->ticket->number}")
            ->assertOk()->viewData('page')['props']['canRerunSummary'];
    }

    /** T2 */
    public function test_the_summary_page_offers_the_rerun_where_the_server_allows_it(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->assertTrue($this->rerunFlag($this->lawyer, 'lawyer'));
        $this->assertTrue($this->rerunFlag($admin, 'admin'));

        // الحكم نفسه في مسار `rerunSummary`: ملخّصٌ اعتمده المستشار لا يُعاد توليده
        $this->ticket->summary->update(['lawyer_approved_at' => now(), 'status' => 'awaiting_admin']);
        $this->assertFalse($this->rerunFlag($this->lawyer, 'lawyer'));
    }

    /** T1 */
    public function test_the_summary_page_links_back_to_an_existing_page(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/lawyer/summary.tsx'));

        $this->assertStringNotContainsString('${base}/approvals', $page, '`/lawyer/approvals` لا مسار له');
        $this->assertStringContainsString("'/lawyer/summaries'", $page);
        $this->actingAs($this->lawyer)->get('/lawyer/summaries')->assertOk();
    }

    /** T3 */
    public function test_the_track_card_links_to_the_file_itself(): void
    {
        $card = (string) file_get_contents(resource_path('js/components/babylon/TicketTrackDecisionCard.tsx'));

        $this->assertStringContainsString('`${base}/cases/${encodeURIComponent(caseNumber)}`', $card);
        $this->assertStringContainsString('`${base}/execs?id=${encodeURIComponent(executionNumber)}`', $card);
    }
}
