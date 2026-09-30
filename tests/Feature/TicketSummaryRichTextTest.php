<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\GenerateTicketSummaryJob;
use App\Mail\SummaryApprovedMail;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Support\SummaryReport;
use App\Support\TicketResult;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **ملخّص التذكرة منسّقاً** (طلب المالك 2026-09-30): يحرّره المحامي/الإدارة بمحرّرٍ منسّق، ويعتمدانه بالمسار نفسه،
 * فيصل العميلَ **بالتنسيق نفسه** — رسالة المحادثة وبطاقة النتيجة والبريد وPDF.
 *
 * كان كلّ حقلٍ نصّاً عاديّاً في `<textarea>`، ويصل العميلَ `nl2br(e())` أسطراً بلا عريضٍ ولا قوائم ولا عناوين.
 */
class TicketSummaryRichTextTest extends TestCase
{
    use RefreshDatabase;

    private const RICH = '<h3>الرأي المبدئي</h3><p>نوصي <strong>برفع دعوى</strong> و<span style="color: #c0392b">المطالبة بالتعويض</span>.</p>'
        .'<ol><li><p>إنذار المدّعى عليه</p></li><li><p>قيد الدعوى في ناجز</p></li></ol>';

    private User $client;

    private User $lawyer;

    private User $admin;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->client = User::factory()->create(['role' => Role::Client, 'email' => 'client@example.test']);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $this->lawyer->syncPermissions(Permission::all());
        $this->admin = User::factory()->create(['role' => Role::Admin]);

        $this->ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-RICH-'.uniqid(), 'type' => 'نزاع تجاري',
            'subject' => 'مطالبة', 'status' => 'محالة للمحامي', 'tone' => 'b-blue',
            'assigned_lawyer_id' => $this->lawyer->id,
        ]);
        TicketSummary::create([
            'ticket_id' => $this->ticket->id, 'lawyer_id' => $this->lawyer->id,
            'case_summary' => 'ملخّص.', 'facts' => "• واقعة أولى\n• واقعة ثانية",
            'key_points' => 'توصيات.', 'attachments_summary' => 'مرفقات.',
            'status' => 'awaiting_lawyer', 'ai_generated' => true,
        ]);
    }

    private function summary(): TicketSummary
    {
        return TicketSummary::where('ticket_id', $this->ticket->id)->firstOrFail();
    }

    public function test_saving_formatted_text_keeps_it_sanitised_and_derives_the_plain_text(): void
    {
        $this->actingAs($this->lawyer)->post("/lawyer/summary/{$this->ticket->number}", [
            'key_points_html' => self::RICH.'<script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">رابط</a>',
        ])->assertRedirect();

        $s = $this->summary();
        $this->assertStringContainsString('<strong>برفع دعوى</strong>', $s->key_points_html);
        $this->assertStringContainsString('<ol>', $s->key_points_html);
        $this->assertStringNotContainsString('<script', $s->key_points_html);
        $this->assertStringNotContainsString('onerror', $s->key_points_html);
        $this->assertStringNotContainsString('javascript:', $s->key_points_html);

        // النصّ العاديّ للذكاء والمعاينات — بلا وسوم، والقوائم نقاط
        $this->assertStringNotContainsString('<', (string) $s->key_points);
        $this->assertStringContainsString('• إنذار المدّعى عليه', (string) $s->key_points);
        $this->assertNotNull($s->edited_at);
    }

    public function test_final_approval_delivers_the_formatting_to_the_client(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)->post("/admin/summary/{$this->ticket->number}/approve", [
            'key_points_html' => self::RICH,
        ])->assertRedirect();

        $s = $this->summary();
        $this->assertTrue($s->isApproved());

        // ١) رسالة المحادثة للعميل
        $message = $this->ticket->messages()->latest('id')->firstOrFail();
        $this->assertStringContainsString('<strong>برفع دعوى</strong>', $message->body);
        $this->assertStringContainsString('<ol>', $message->body);
        $this->assertStringContainsString('color: #c0392b', $message->body);

        // ٢) بطاقة النتيجة (التوصيات بتنسيقها، والوقائع القديمة نصّاً قائمةً)
        $card = TicketResult::card($this->ticket->fresh(), $s);
        $this->assertStringContainsString('<h3>الرأي المبدئي</h3>', $card);
        $this->assertStringContainsString('<li>واقعة أولى</li>', $card);

        // ٣) البريد
        Mail::assertQueued(SummaryApprovedMail::class, function (SummaryApprovedMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, '<strong>برفع دعوى</strong>') && str_contains($html, '<ol>');
        });

        // ٤) PDF الملخّص
        $doc = SummaryReport::doc($this->ticket, $s, 'عميل', 'محامٍ');
        $this->assertStringContainsString('<strong>برفع دعوى</strong>', json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    public function test_an_old_plain_summary_is_shown_as_paragraphs_and_lists(): void
    {
        $html = $this->summary()->html('facts');

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>واقعة أولى</li>', $html);
    }

    public function test_new_plain_text_drops_the_stale_formatted_copy(): void
    {
        $s = $this->summary();
        $s->update(TicketSummary::editableInput(['key_points_html' => self::RICH]));
        $this->assertNotNull($s->fresh()->key_points_html);

        // إعادة التوليد الآليّة تكتب النصّ وحده (`GenerateTicketSummaryJob`)
        $s->update(['key_points' => 'توصياتٌ جديدة من التحليل.']);

        $s->refresh();
        $this->assertNull($s->key_points_html);
        $this->assertStringContainsString('توصياتٌ جديدة من التحليل.', $s->html('key_points'));
        $this->assertTrue(class_exists(GenerateTicketSummaryJob::class));
    }

    public function test_the_approved_summary_is_still_locked(): void
    {
        $this->summary()->update(['status' => 'approved', 'approved_at' => now()]);

        $this->actingAs($this->admin)->post("/admin/summary/{$this->ticket->number}", ['key_points_html' => self::RICH])
            ->assertStatus(422);
    }

    public function test_the_editor_screen_uses_the_rich_editor_and_formatted_values(): void
    {
        $this->actingAs($this->lawyer)->get("/lawyer/summary/{$this->ticket->number}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('lawyer/summary')
                ->where('summary.html.facts', fn ($v) => str_contains((string) $v, '<li>واقعة أولى</li>'))
            );

        $page = (string) file_get_contents(resource_path('js/pages/lawyer/summary.tsx'));
        $this->assertStringContainsString('<RichTextEditor', $page);
        $this->assertStringNotContainsString('setVal(f.key, e.target.value)', $page, 'حقول الملخّص لم تعد مربّعات نصٍّ عاديّ');
    }
}
