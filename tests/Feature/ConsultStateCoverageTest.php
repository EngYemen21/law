<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **كلُّ حالةِ استشارةٍ تُعرض لكلّ دورٍ كما وقعت — لا أكثر ولا أقلّ.**
 *
 * مسحُ اللوحات الأربع (الإدارة · الموظّف · المحامي · الموكّل) كشف عيوباً من عائلةٍ
 * واحدة: فرعٌ جامعٌ يبتلع حالةً لم يُكتب لها، ومرشِّحاتٌ مستقلّةٌ تضع البطاقة في
 * عمودين. أخطرُها أنّ الموكّل يُطالَب بمستنداتٍ وبطاقتُه تقول «بانتظار الجلسة» وتُحسب
 * «قادمة مؤكدة» — فيقرأ «لا شيء عليك» وملفُّه موقوفٌ به.
 *
 * ومعها قرارُ المالك: «استلام الاستشارة» أُزيل، فطُويت حالتُه «قيد مراجعة الموظف»
 * ومسارُه `take()`، وصار طلبُ المستندات يُطلب من «جديدة».
 */
class ConsultStateCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function src(string $path): string
    {
        return (string) file_get_contents(resource_path($path));
    }

    private function consult(User $client, array $extra = []): Consult
    {
        return Consult::create(array_merge([
            'user_id' => $client->id, 'ref' => 'CN-COV-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'جديدة',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-blue', 'lawyer' => 'مستشار',
        ], $extra));
    }

    // ————— ١ · الأثمن: ما ينتظره المكتب من الموكّل يصل الموكّل —————

    public function test_a_document_request_on_a_new_consult_reaches_the_client_as_their_action(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consult($client, ['lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id]);

        // يُطلب من «جديدة» — بعد إزالة الاستلام لم يبقَ طريقٌ آخر إلى هذه الحالة
        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/reqdocs", ['docs' => 'نسخة العقد الموقّعة'])
            ->assertRedirect();

        $card = $consult->fresh()->toClientCard();
        $this->assertSame('بانتظار استكمال البيانات', $card['status']);
        $this->assertContains('نسخة العقد الموقّعة', $card['missing'], 'المطلوب يصل الموكّل بنصّه');

        // موقوفٌ على الموكّل ⇒ ليس «قادمة مؤكدة» بل «بانتظار الإجراء»
        $this->actingAs($client)->get('/myconsults')->assertInertia(fn ($p) => $p
            ->component('myconsults')
            ->where('stats.upcoming', 0)
            ->where('stats.pendingBooking', 1));

        $ui = $this->src('js/pages/myconsults.tsx');
        $this->assertStringContainsString("c.status !== 'بانتظار استكمال البيانات' &&", $ui);
        $this->assertStringContainsString('<Badge text="بانتظار مستنداتك" tone="b-amber" />', $ui);
    }

    public function test_the_upload_button_opens_the_consult_ticket_for_its_owner_only(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $stranger = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'TK-COV-1',
            'type' => 'استشارة', 'subject' => 'نزاع تجاري', 'status' => 'جديدة',
        ]);
        $withTicket = $this->consult($client, ['ticket_id' => $ticket->id, 'status' => 'بانتظار استكمال البيانات']);
        $ticketless = $this->consult($client, ['status' => 'بانتظار استكمال البيانات']);

        // الرفعُ في محادثة التذكرة حيث يراه الفريق — ورقمُها لا يُرسَل في بطاقة العميل
        $this->actingAs($client)->get("/consults/{$withTicket->id}/documents")
            ->assertRedirect(route('tickets.show', $ticket));
        // ولا طريقَ مسدود لما لا تذكرة له
        $this->actingAs($client)->get("/consults/{$ticketless->id}/documents")
            ->assertRedirect(route('documents'));
        $this->actingAs($stranger)->get("/consults/{$withTicket->id}/documents")->assertForbidden();

        $ui = $this->src('js/pages/myconsults.tsx');
        $this->assertStringContainsString('router.visit(`/consults/${c.id}/documents`)', $ui);
        $this->assertStringNotContainsString('/tickets?consult=', $ui, 'صفحة التذاكر تتجاهل هذا المعامل');
    }

    // ————— ٢ · «معتمدة» تعني معتمدة —————

    public function test_an_ended_session_is_not_shown_approved_before_its_summary_is(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $card = $this->consult($client, [
            'status' => 'منتهية', 'session' => 'منتهية', 'summary' => 'مسودّة لم تُعتمد',
        ])->toClientCard();

        $this->assertFalse($card['summaryApproved']);
        $this->assertNull($card['summary']);
        $this->assertTrue($card['summaryPending']);

        $this->assertMatchesRegularExpression(
            '/\{c\.summaryApproved\s*\?\s*<Badge text="منتهية ومعتمدة"/u',
            $this->src('js/pages/myconsults.tsx'),
            'الشارة الخضراء تتبع الاعتماد لا انتهاء الجلسة'
        );
    }

    // ————— ٣ · بطاقةٌ واحدة في عمودٍ واحد —————

    /** @return array<int,string> */
    private function columnsOf(string $src, string $type): array
    {
        $this->assertSame(1, preg_match("/export type {$type} = ([^;]+);/", $src, $m), "{$type} غير موجود");
        preg_match_all("/'([a-z_]+)'/", $m[1], $cols);

        return $cols[1];
    }

    public function test_each_kanban_is_a_partition_without_holes_or_duplicates(): void
    {
        foreach ([
            // [الملف، النوع، المصنِّف، عدد الأعمدة، بداية منطقة الكانبان، نهايتها]
            // النهاية لازمة عند الموظّف: بعد الكانبان عدّاداتُ عبءٍ لكلّ محامٍ وقناة
            // تُرشِّح `c.lawyer`/`c.channel` — إحصاءٌ مشروع لا أعمدة.
            ['js/pages/employee/consults.tsx', 'EmpKanbanCol', 'empKanbanColumnOf', 5, 'items: filteredItems.filter(', '] as const'],
            ['js/pages/lawyer/consults.tsx', 'LawyerKanbanCol', 'lawyerKanbanColumnOf', 4, 'className="lawyer-kanban-board"', null],
        ] as [$file, $type, $fn, $count, $region, $end]) {
            $src = $this->src($file);
            $cols = $this->columnsOf($src, $type);
            $this->assertCount($count, $cols, "{$file}: عدد الأعمدة تغيّر");

            // لا ثقب: لكلّ قيمةٍ يعيدها المصنِّف عمودٌ يعرضها
            foreach ($cols as $col) {
                $this->assertStringContainsString(
                    "filter((c) => {$fn}(c) === '{$col}')",
                    $src,
                    "{$file}: «{$col}» قيمةٌ بلا عمود — بطاقاتها تختفي"
                );
            }

            // لا ازدواج: كلُّ مرشِّحٍ في منطقة الكانبان يمرّ بالمصنِّف الواحد —
            // مرشِّحٌ خامٌ على `status`/`session` هو ما كان يضع البطاقة في عمودين
            $at = strpos($src, $region);
            $this->assertNotFalse($at, "{$file}: منطقة الكانبان غير موجودة");
            $board = substr($src, $at);
            if ($end !== null) {
                $stop = strpos($board, $end);
                $this->assertNotFalse($stop, "{$file}: نهاية منطقة الكانبان غير موجودة");
                $board = substr($board, 0, $stop);
            }
            $this->assertGreaterThanOrEqual($count, substr_count($board, '.filter((c) =>'), "{$file}: المنطقة لا تحوي الأعمدة");
            $all = substr_count($board, '.filter((c) =>');
            $partitioned = substr_count($board, ".filter((c) => {$fn}(c) === '");
            $this->assertSame($all, $partitioned, "{$file}: عمودٌ يرشّح بغير {$fn} — عادت القسمة مرشِّحاتٍ مستقلّة");
        }
    }

    public function test_the_lawyer_upcoming_counter_is_the_waiting_column(): void
    {
        $src = $this->src('js/pages/lawyer/consults.tsx');

        // `cancelRequest` لا يمسّ `session` — فشرطُ «بانتظار الجلسة» يعدّ الملغاة قادمة
        $this->assertStringContainsString(
            "const upcoming = items.filter((c) => lawyerKanbanColumnOf(c) === 'waiting').length;",
            $src
        );
        $this->assertStringNotContainsString("c.session === 'بانتظار الجلسة' || c.status === 'محالة للمحامي'", $src);
        // ولا خريطةَ نغماتٍ خاصّة تخالف `sessTone`
        $this->assertStringNotContainsString("'جلسة جارية': 'b-", $src);
    }

    // ————— ٤ · قرار المالك: الاستلام أُزيل، وطلبُ المستندات من «جديدة» —————

    public function test_the_take_path_and_its_status_are_gone(): void
    {
        $this->assertNotContains('قيد مراجعة الموظف', Consult::STATUSES, 'حالةٌ بلا كاتب منذ أُزيل الاستلام');

        foreach (['admin', 'employee', 'lawyer'] as $prefix) {
            $this->assertFalse(Route::has("{$prefix}.consults.take"), "مسار الاستلام ما زال مفتوحاً لدى {$prefix}");
        }
    }

    public function test_the_document_request_button_shows_on_a_new_consult(): void
    {
        $ui = $this->src('js/lib/consult-ui.tsx');

        $this->assertStringContainsString(
            "const showEmpActions = c.status === 'جديدة'\n    || c.status === 'بانتظار استكمال البيانات';",
            str_replace("\r", '', $ui)
        );
        // والحاوية لا تُصيَّر فارغةً — أزرارُها مشروطةٌ بالشرط نفسه
        $this->assertStringContainsString('{showEmpActions && (', $ui);

        // وطلبُ المستندات لا يُعلن «جارٍ التحليل…» — قيسَ في المتصفّح: `busy` مشتركٌ بين
        // الأفعال، فكان زرُّ المعالجة يقول ذلك أثناء أيّ فعلٍ آخر
        $this->assertStringNotContainsString("{busy ? 'جارٍ التحليل…'", $ui);
        $this->assertStringContainsString("{running === 'analyze' ? 'جارٍ التحليل…'", $ui);
    }

    // ————— ٥ · ما يخرج من شاشةٍ يُقال أين ذهب —————

    public function test_a_cancelled_request_says_where_it_went(): void
    {
        $this->assertStringContainsString(
            'تجده في «الاستشارات» ضمن «منتهية ومغلقة»',
            $this->src('js/pages/admin/consult-requests.tsx')
        );
        // والعنوان يطابق عمود الإدارة فعلاً
        $this->assertStringContainsString("title: '5. منتهية ومغلقة'", $this->src('js/pages/admin/consults.tsx'));
    }
}
