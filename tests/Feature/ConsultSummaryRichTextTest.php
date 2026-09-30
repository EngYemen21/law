<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Jobs\ExtractConsultDecisionsJob;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ConsultReport;
use App\Support\TicketResult;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **ملخّص الاستشارة منسّقاً** (طلب المالك 2026-09-30) — نظير `TicketSummaryRichTextTest`: يحرّره المحامي/الإدارة
 * بمحرّرٍ منسّق فيصل الموكّلَ **بتنسيقه** (نافذة الملخّص، البثّ، بطاقة النتيجة، تقرير PDF).
 *
 * ومعه عيبان ثبتا بالفحص: زرّ «اعتماد وإرسال للعميل» يظهر للموظّف ولا مسار اعتمادٍ له (٤٠٤)، واستيراد استشارةٍ
 * ذات قرارات في محرّر الصياغة ينهار (`e()` على مصفوفة ⇐ TypeError).
 */
class ConsultSummaryRichTextTest extends TestCase
{
    use RefreshDatabase;

    private const RICH = '<h3>خلاصة الجلسة</h3><p>ننصح <strong>بالتسوية الودّيّة</strong> أوّلاً.</p>'
        .'<ol><li><p>إرسال إنذار</p></li><li><p>مهلة أسبوعين</p></li></ol>';

    private User $client;

    private User $lawyer;

    private User $admin;

    private Consult $consult;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $this->lawyer->syncPermissions(Permission::all());
        $this->admin = User::factory()->create(['role' => Role::Admin]);

        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-CRICH-'.uniqid(), 'type' => 'نزاع تجاري',
            'subject' => 'مطالبة', 'status' => 'محالة للمحامي', 'tone' => 'b-blue', 'assigned_lawyer_id' => $this->lawyer->id,
        ]);
        $this->consult = Consult::create([
            'user_id' => $this->client->id, 'ticket_id' => $ticket->id, 'ref' => 'CN-RICH-'.uniqid(), 'subject' => 'مطالبة مالية',
            'type' => 'استشارة', 'channel' => 'video', 'status' => 'منتهية', 'session' => 'منتهية', 'tone' => 'b-green',
            'assigned_lawyer_id' => $this->lawyer->id, 'lawyer' => $this->lawyer->name,
            'summary' => "- نقطة أولى\n- نقطة ثانية",
        ]);
    }

    public function test_saving_formatted_text_keeps_it_sanitised_and_derives_the_plain_text(): void
    {
        Queue::fake();

        $this->actingAs($this->lawyer)->post("/lawyer/consults/{$this->consult->id}/summary", [
            'summary_html' => self::RICH.'<script>alert(1)</script><img src="x" onerror="alert(2)">',
        ])->assertRedirect();

        $c = $this->consult->fresh();
        $this->assertStringContainsString('<strong>بالتسوية الودّيّة</strong>', $c->summary_html);
        $this->assertStringNotContainsString('<script', $c->summary_html);
        $this->assertStringNotContainsString('onerror', $c->summary_html);
        $this->assertStringNotContainsString('<', (string) $c->summary, 'النصّ العاديّ للذكاء والقرارات بلا وسوم');
        $this->assertStringContainsString('• إرسال إنذار', (string) $c->summary);
        $this->assertNotNull($c->summary_edited_at);
        Queue::assertPushed(ExtractConsultDecisionsJob::class);
    }

    public function test_a_formatting_only_change_keeps_the_decisions(): void
    {
        $this->consult->update(Consult::editableInput(['summary_html' => '<p>ننصح بالتسوية.</p>']) + ['decisions' => ['التسوية']]);
        Queue::fake();

        $this->actingAs($this->lawyer)->post("/lawyer/consults/{$this->consult->id}/summary", [
            'summary_html' => '<p>ننصح <strong>بالتسوية.</strong></p>',
        ])->assertRedirect();

        $c = $this->consult->fresh();
        $this->assertStringContainsString('<strong>', $c->summary_html, 'التنسيق وحده يُحفظ');
        $this->assertSame(['التسوية'], $c->decisions, 'النصّ لم يتغيّر — القرارات باقية');
        Queue::assertNotPushed(ExtractConsultDecisionsJob::class);
    }

    public function test_the_client_receives_the_formatting_once_approved(): void
    {
        $this->consult->update(Consult::editableInput(['summary_html' => self::RICH]));

        // قبل الاعتماد: محجوب كالنصّ
        $this->assertNull($this->consult->fresh()->toClientCard()['summaryHtml']);

        $this->actingAs($this->lawyer)->post("/lawyer/consults/{$this->consult->id}/summary/approve")->assertRedirect();
        $this->actingAs($this->admin)->post("/admin/consults/{$this->consult->id}/summary/approve")->assertRedirect();

        $c = $this->consult->fresh();
        $this->assertTrue($c->summaryApproved());
        // نافذة «الملخّص» في «استشاراتي» والبثّ الحيّ
        $this->assertStringContainsString('<strong>بالتسوية الودّيّة</strong>', (string) $c->toClientCard()['summaryHtml']);
        $this->assertStringContainsString('<ol>', (string) (new ConsultStatusBroadcast($c))->broadcastWith()['summaryHtml']);
        // بطاقة النتيجة في المحادثة، وتقرير PDF
        $this->assertStringContainsString('<h3>خلاصة الجلسة</h3>', TicketResult::card($c->ticket, null));
        $doc = ConsultReport::doc($c, 'عميل');
        $section = collect($doc['blocks'])->first(fn ($b) => ($b['title'] ?? '') === '٤. ملخص الاستشارة');
        $this->assertStringContainsString('<strong>بالتسوية الودّيّة</strong>', $section['html']);
    }

    public function test_an_old_plain_summary_is_shown_as_a_list(): void
    {
        $this->assertStringContainsString('<li>نقطة أولى</li>', $this->consult->html('summary'));
        $this->assertStringContainsString('<li>نقطة أولى</li>', (string) $this->consult->toCard()['summaryHtml']);
    }

    public function test_the_approved_summary_is_still_locked(): void
    {
        $this->consult->update(['summary_approved_at' => now(), 'summary_approved_by' => $this->admin->id]);

        $this->actingAs($this->admin)->post("/admin/consults/{$this->consult->id}/summary", ['summary_html' => self::RICH])
            ->assertStatus(422);
    }

    /** العيب ١: زرّ الاعتماد يتبع وجود مساره — الموظّف لا مسار له (قرار المالك 2026-09-14). */
    public function test_the_approve_button_is_offered_only_where_its_route_exists(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::all());

        $flag = fn (User $u, string $prefix) => $this->actingAs($u)->get("/{$prefix}/consult?ref={$this->consult->ref}")
            ->assertOk()->viewData('page')['props']['canApproveSummary'];

        $this->assertFalse($flag($employee, 'employee'));
        $this->assertTrue($flag($this->lawyer, 'lawyer'));
        $this->assertTrue($flag($this->admin, 'admin'));
    }

    /** العيب ٢: استيراد محضر استشارةٍ ذات قرارات في محرّر الصياغة. */
    public function test_importing_a_consult_with_decisions_into_the_editor_works(): void
    {
        $this->consult->update(Consult::editableInput(['summary_html' => self::RICH]) + ['decisions' => ['إرسال إنذار', 'مهلة أسبوعين']]);

        $this->actingAs($this->lawyer)->get("/lawyer/editor/create?importType=session_summary&id={$this->consult->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('lawyer/editor')
                ->where('incomingDraft', fn ($html) => str_contains((string) $html, '<li>إرسال إنذار</li>')
                    && str_contains((string) $html, '<strong>بالتسوية الودّيّة</strong>'))
            );
    }
}
