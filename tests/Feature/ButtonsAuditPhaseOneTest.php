<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **جرد الأزرار — المرحلة ١: المعطوب وفي غير مكانه** (طلب المالك 2026-10-03).
 *
 * كلّ بندٍ هنا ثبت في المتصفّح على الكود القديم قبل إصلاحه (law_proof)، وكلّ حارسٍ يفشل على القديم:
 * ١ رابط القضيّة للطاقم · ٢ «إنهاء بلا تدوين» · ٣ اعتماد مقترح الإغلاق السريع · ٤ المكوّن الميّت ·
 * ٦ «طلب تنفيذ» · ٧ الحجز من تذكرة · ٨ «محرر الصياغة» · ٩ «تحديث من Zoom» · ١٠ «تحويل» السريع · ١١ أزرار ميّتة.
 */
class ButtonsAuditPhaseOneTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    private function src(string $rel): string
    {
        return (string) file_get_contents(resource_path($rel));
    }

    // ── ١ ──
    public function test_staff_case_links_follow_the_role_base(): void
    {
        $ui = $this->src('js/lib/consult-ui.tsx');

        $this->assertStringNotContainsString('href={`/cases/${', $ui, '`/cases/{no}` صفحة العميل وحده — الطاقم يُعاد إلى لوحته');
        $this->assertStringContainsString('export function caseHref(base: string, caseNo: string)', $ui);
        $this->assertGreaterThanOrEqual(3, substr_count($ui, 'caseHref(base,'));
    }

    // ── ٢ ──
    public function test_end_without_notes_sends_no_notes_from_one_shared_modal(): void
    {
        $ui = $this->src('js/lib/consult-ui.tsx');

        $this->assertStringContainsString("onClick={() => onEnd('')}", $ui, '«بلا تدوين» يرسل فارغاً');
        $this->assertStringContainsString('onClick={() => onEnd(notes.trim())}', $ui);

        // النافذة واحدة: لا نسخة ثانية تحمل الزرّ
        $copies = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js'))) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.tsx')) {
                $copies += substr_count((string) file_get_contents($f->getPathname()), '<Icon name="check" /> إنهاء بلا تدوين');
            }
        }
        $this->assertSame(1, $copies);
        $this->assertStringContainsString('<EndSessionModal', $this->src('js/pages/admin/consultrecv.tsx'));
    }

    // ── ٣ ──
    public function test_a_close_proposal_without_a_category_opens_the_approval_form(): void
    {
        $card = $this->src('js/components/babylon/TicketTrackDecisionCard.tsx');

        $this->assertStringContainsString("governance?.proposedTrack === 'close' && !closureCode", $card);
        $this->assertStringContainsString('اختيار سبب الإغلاق والاعتماد', $card);
    }

    // ── ٤ ──
    public function test_the_dead_close_modal_is_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('js/components/babylon/CloseTicketModal.tsx'));
        $this->assertStringContainsString("from '@/lib/closure-reasons'", $this->src('js/components/babylon/TicketTrackDecisionCard.tsx'));
    }

    // ── ٦ ──
    public function test_client_execution_request_buttons_open_the_request_form(): void
    {
        $this->assertStringContainsString("execrequest: '/tickets/new?department=enforcement'", $this->src('js/lib/data.ts'));

        $dash = $this->src('js/pages/dashboard.tsx');
        $this->assertSame(2, substr_count($dash, "go('execrequest')"), '«طلب تنفيذ قضائي» و«تقديم طلب تنفيذ»');
        // و«تتبع القرار» صار يفتح الملفّ نفسه لا القائمة (جرد تبويبات العميل 2026-10-04 — `ClientTabsOwnershipTest`)
        $this->assertSame(0, substr_count($dash, "go('execs')"), 'لا زرّ في الرئيسيّة يفتح قائمة التنفيذ عوض ملفّه');
    }

    // ── ٧ ──
    public function test_booking_from_a_ticket_preselects_it_only_when_bookable(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketWithApprovedOpinion($client, ['status' => 'بانتظار حجز الاستشارة']);
        $foreign = $this->ticketWithApprovedOpinion(User::factory()->create(['role' => Role::Client]), ['status' => 'بانتظار حجز الاستشارة']);

        $this->actingAs($client)->get('/book?ticket='.$ticket->number)
            ->assertInertia(fn ($page) => $page->where('selectedTicket', $ticket->number));
        $this->actingAs($client)->get('/book?ticket='.$foreign->number)
            ->assertInertia(fn ($page) => $page->where('selectedTicket', null));
        $this->actingAs($client)->get('/book')
            ->assertInertia(fn ($page) => $page->where('selectedTicket', null));

        $this->assertStringContainsString('/book?ticket=${encodeURIComponent(t.no)}', $this->src('js/pages/tickets.tsx'));
        $this->assertStringContainsString('/book?ticket=${encodeURIComponent(ticket.no)}', $this->src('js/pages/ticketchat.tsx'));
    }

    // ── ٨ ──
    public function test_the_summary_editor_button_imports_this_summary(): void
    {
        $page = $this->src('js/pages/lawyer/summary.tsx');

        $this->assertDoesNotMatchRegularExpression('/href=\{`[^`]*type=summary/', $page, 'الخادم لا يقرأ `type` — محرّرٌ فارغ');
        $this->assertSame(2, substr_count($page, 'importType=ticket_summary&id=${summary.id}'));
    }

    // ── ٩ ──
    public function test_zoom_sync_is_offered_only_where_the_server_accepts_it(): void
    {
        $base = ['user_id' => User::factory()->create(['role' => Role::Client])->id, 'subject' => 'نزاع', 'channel' => Consult::CHANNEL_VIDEO, 'lawyer' => 'محامٍ', 'status' => 'منتهية'];

        $noMeeting = Consult::create($base + ['ref' => 'CN-ZS-1', 'session' => 'منتهية']);
        $notEnded = Consult::create($base + ['ref' => 'CN-ZS-2', 'session' => 'بانتظار الجلسة', 'meet_id' => '81800000001']);
        $approved = Consult::create($base + ['ref' => 'CN-ZS-3', 'session' => 'منتهية', 'meet_id' => '81800000002', 'summary_approved_at' => now()]);
        $ok = Consult::create($base + ['ref' => 'CN-ZS-4', 'session' => 'منتهية', 'meet_id' => '81800000003']);

        foreach ([$noMeeting, $notEnded, $approved] as $c) {
            $this->assertFalse($c->toCard()['zoomSyncable'], $c->ref);
            $this->actingAs(User::factory()->create(['role' => Role::Admin]))
                ->post("/admin/consults/{$c->id}/zoom-sync")->assertStatus(422);
        }
        $this->assertTrue($ok->toCard()['zoomSyncable']);

        $this->assertStringContainsString('drawerConsult.zoomSyncable &&', $this->src('js/pages/employee/consults.tsx'));
        $this->assertStringNotContainsString("c.session === 'منتهية' && c.channel === 'مرئية'", $this->src('js/lib/consult-ui.tsx'));
    }

    // ── ١٠ ──
    public function test_quick_transfer_shows_only_for_a_reassignable_ticket(): void
    {
        $this->assertStringContainsString('onTransfer && ticket.isReassignable &&', $this->src('js/components/babylon/QuickTicketModal.tsx'));
    }

    // ── ١١ ──
    public function test_the_unreachable_client_buttons_in_the_staff_card_are_gone(): void
    {
        $flow = $this->src('js/pages/execflow.tsx');

        $this->assertSame(1, substr_count($flow, "act('acceptOffer')"), 'أزرار العرض للعميل في `ClientFlowCard` وحدها');
    }
}
