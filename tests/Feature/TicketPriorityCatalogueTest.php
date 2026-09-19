<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **كتالوج أولويّة التذكرة — مصدرٌ واحد** (بلاغ المالك 2026-09-13: «تحقّق من الفلترة»).
 *
 * كان الكتالوج غائباً فتفرّقت المفردات على أربع شاشاتٍ ومتحكّمَين. وقد قيس على قاعدة
 * التطوير فوُجد فيها `عالية`(٢) و`متوسطة`(٤) لا غير، بينما مرشّح المحامي يعرض
 * «عاجلة/عادية/منخفضة» — **فلا خيارَ منها يطابق صفّاً**، والقيمتان الغالبتان غير معروضتين.
 * وفرزُ الإدارة «العاجلة أولاً» كان يرتّب `'عاجلة'` (المعدومة) أوّلاً فتسقط `'عالية'` في
 * `ELSE` **دون** المتوسّطة: يضغط المدير الفرز فتنزل تذاكره العاجلة إلى الذيل.
 */
class TicketPriorityCatalogueTest extends TestCase
{
    use RefreshDatabase;

    /** المفردات الميّتة التي كانت موزَّعةً على الشاشات — لا يعود منها شيء. */
    private const DEAD = ['عاجلة', 'عاجلة جداً', 'طارئة', 'عاجل جداً', 'حرجة', 'عادية'];

    /** يُسقط تعليقات PHP وJS (‏`//` و`/* … *&#47;` و`{/* … *&#47;}`) ويُبقي الكود. */
    private static function withoutComments(string $body): string
    {
        $body = preg_replace('#/\*.*?\*/#s', ' ', $body) ?? $body;

        return preg_replace('#^\s*//.*$#m', ' ', $body) ?? $body;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function ticket(User $client, string $number, ?string $priority): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id, 'number' => $number, 'type' => 'نزاع تجاري',
            'status' => 'جديدة', 'tone' => 'b-blue', 'priority' => $priority,
        ]);
    }

    // ── الكتالوج ──

    /** الخادم والواجهة يحملان القائمة نفسها حرفاً — نسختان تتباعدان تُنتجان خياراً ميّتاً. */
    public function test_the_server_and_the_screen_share_one_catalogue(): void
    {
        $lib = (string) file_get_contents(resource_path('js/lib/employee-data.ts'));

        $this->assertSame(['عالية', 'متوسطة', 'منخفضة'], TicketJourney::PRIORITIES);
        $this->assertStringContainsString(
            "export const TICKET_PRIORITIES = ['".implode("', '", TicketJourney::PRIORITIES)."'];",
            $lib,
            'نسخة الواجهة يجب أن تطابق الخادم حرفاً'
        );
        $this->assertTrue(TicketJourney::isUrgent(TicketJourney::PRIORITIES[0]));
        $this->assertFalse(TicketJourney::isUrgent(TicketJourney::PRIORITIES[1]));
        $this->assertFalse(TicketJourney::isUrgent(null));
    }

    /** والافتراض يطابق ما تكتبه الهجرة — وإلّا دخلت القاعدة قيمةٌ خارج الكتالوج. */
    public function test_the_default_priority_is_inside_the_catalogue(): void
    {
        $this->assertContains(TicketJourney::PRIORITY_DEFAULT, TicketJourney::PRIORITIES);
    }

    /** ولا مفردةَ ميّتة باقيةٌ في أيّ شاشةِ تذاكر أو متحكّم. */
    public function test_no_dead_priority_word_survives_anywhere(): void
    {
        $files = [
            resource_path('js/pages/lawyer/tickets.tsx'),
            resource_path('js/pages/employee/tickets.tsx'),
            resource_path('js/pages/employee/transfer.tsx'),
            resource_path('js/pages/admin/tickets.tsx'),
            resource_path('js/pages/admin/distribute.tsx'),
            app_path('Http/Controllers/Employee/TicketController.php'),
            app_path('Http/Controllers/Employee/TransferController.php'),
            app_path('Http/Controllers/Admin/DistributeController.php'),
            app_path('Http/Controllers/Admin/TicketController.php'),
        ];

        foreach ($files as $file) {
            // **التعليقات تُستثنى.** المفردة الميّتة تُذكر في تعليقٍ يشرح العطل الذي أُغلق —
            // وذاك توثيقٌ مقصود. المقيس هنا أن لا يقرأها **كود**.
            $body = self::withoutComments((string) file_get_contents($file));
            foreach (self::DEAD as $word) {
                $this->assertStringNotContainsString(
                    "'{$word}'",
                    $body,
                    basename($file).' ما زال يحمل المفردة الميّتة «'.$word.'»'
                );
            }
        }
    }

    // ── الفرز ──

    /** «الأعلى أولاً» يضع العالية أوّلاً فعلاً — كانت تسقط دون المتوسّطة. */
    public function test_sorting_by_priority_puts_the_highest_first(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->ticket($client, 'SB-LOW-1', 'منخفضة');
        $this->ticket($client, 'SB-MID-1', 'متوسطة');
        $this->ticket($client, 'SB-HIGH-1', 'عالية');

        $this->actingAs($this->admin())
            ->get(route('admin.tickets', ['sort' => 'priority']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('tickets.data.0.no', 'SB-HIGH-1')
                ->where('tickets.data.1.no', 'SB-MID-1')
                ->where('tickets.data.2.no', 'SB-LOW-1'));
    }

    /** وقيمةٌ خارج الكتالوج (صفٌّ قديم) تأتي آخراً ولا تسقط من القائمة. */
    public function test_an_unknown_stored_priority_sorts_last_but_is_not_dropped(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->ticket($client, 'SB-ODD-1', 'قيمة قديمة');
        $this->ticket($client, 'SB-HIGH-2', 'عالية');

        $this->actingAs($this->admin())
            ->get(route('admin.tickets', ['sort' => 'priority']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('tickets.data.0.no', 'SB-HIGH-2')
                ->where('tickets.data.1.no', 'SB-ODD-1'));
    }

    // ── الترشيح ──

    /** مرشّح الأولويّة يطابق صفوفاً فعليّة لكلّ خيارٍ معروض. */
    public function test_every_offered_priority_option_matches_real_rows(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        foreach (TicketJourney::PRIORITIES as $i => $priority) {
            $this->ticket($client, 'SB-F-'.$i, $priority);
        }

        foreach (TicketJourney::PRIORITIES as $priority) {
            $this->actingAs($this->admin())
                ->get(route('admin.tickets', ['priority' => $priority]))
                ->assertOk()
                ->assertInertia(fn ($p) => $p->has('tickets.data', 1)
                    ->where('tickets.data.0.priority', $priority));
        }
    }

    // ── الكتابة ──

    /** ولا تدخل القاعدة قيمةٌ خارج الكتالوج — كان التحقّق `string|max:20` يقبل أيّ نصّ. */
    public function test_opening_a_ticket_refuses_a_priority_outside_the_catalogue(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        Permission::findOrCreate('فتح التذاكر والمتابعة');
        $client->givePermissionTo('فتح التذاكر والمتابعة');

        $this->actingAs($client)
            ->post(route('tickets.store'), [
                'type' => 'نزاع تجاري', 'subject' => 'موضوع', 'desc' => 'وصف كافٍ للطلب',
                'priority' => 'عاجلة جداً',
            ])
            ->assertSessionHasErrors('priority');

        $this->assertSame(0, Ticket::where('priority', 'عاجلة جداً')->count());
    }
}
