<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Support\SearchText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **البحث العربيّ يجد ما يبحث عنه المستخدم** (بلاغ المالك 2026-09-13: «تحقّق من الفلترة»).
 *
 * ثلاث علل كانت في كلّ مرشّح بحثٍ في التطبيق:
 * ١. الأرقام العربيّة-الهنديّة: المراجع مخزَّنة لاتينيّةً، ومن يكتب `٢٠٢٦` يحصل على صفر.
 * ٢. الهمزة والتاء المربوطة: «احمد» لا يجد «أحمد»، و«محكمه» لا تجد «محكمة».
 * ٣. `%` و`_` غير مهرَّبين: بحثٌ فيه `%` يُرجع الجدول كلّه.
 *
 * والتطبيع على الطرفين — الإبرة والعمود — إذ لا يكفي تطبيع الإبرة ما دام المخزون خامّاً.
 */
class ArabicSearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function ticket(string $number, string $subject, ?User $owner = null): Ticket
    {
        return Ticket::create([
            'user_id' => ($owner ?? User::factory()->create(['role' => Role::Client]))->id,
            'number' => $number, 'type' => 'نزاع تجاري', 'subject' => $subject,
            'status' => 'جديدة', 'tone' => 'b-blue',
        ]);
    }

    /** @return array<int, string> أرقام التذاكر التي أعادها البحث */
    private function search(string $q): array
    {
        $found = [];
        $this->actingAs($this->admin())
            ->get(route('admin.tickets', ['q' => $q]))
            ->assertOk()
            ->assertInertia(function ($page) use (&$found) {
                foreach ((array) $page->toArray()['props']['tickets']['data'] as $row) {
                    $found[] = (string) $row['no'];
                }
            });

        return $found;
    }

    // ── الدالّة نفسها ──

    public function test_folding_maps_digits_hamza_and_taa_marbuta(): void
    {
        $this->assertSame('2026', SearchText::fold('٢٠٢٦'));
        $this->assertSame('2026', SearchText::fold('۲۰۲۶'));
        $this->assertSame('احمد', SearchText::fold('أحمد'));
        $this->assertSame('احمد', SearchText::fold('إحمد'));
        $this->assertSame('محكمه', SearchText::fold('محكمة'));
        $this->assertSame('علي', SearchText::fold('على'));
        $this->assertSame('محمد', SearchText::fold('محـمـد'), 'التطويل يُسقط');
    }

    /** و`%` و`_` يُهرَّبان فلا يصيران محرفَي بدل. */
    public function test_wildcards_are_escaped_in_the_needle(): void
    {
        $this->assertStringContainsString('\%', SearchText::needle('خصم 50%'));
        $this->assertStringContainsString('\_', SearchText::needle('REF_1'));
    }

    // ── البحث الحيّ ──

    /** الرقم العربيّ-الهنديّ يجد المرجع المخزَّن لاتينيّاً. */
    public function test_arabic_indic_digits_find_a_latin_reference(): void
    {
        $this->ticket('SB-2026-1042', 'مطالبة مالية');
        $this->ticket('SB-2025-0001', 'أخرى');

        $this->assertSame(['SB-2026-1042'], $this->search('٢٠٢٦-١٠٤٢'));
    }

    /** والاسم بلا همزة يجد المهموز — والعكس. */
    public function test_hamza_insensitive_search_finds_the_name(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'أحمد الشمري']);
        $this->ticket('SB-H-1', 'موضوع', $client);

        $this->assertSame(['SB-H-1'], $this->search('احمد'));
        $this->assertSame(['SB-H-1'], $this->search('أحمد'));
    }

    /** والتاء المربوطة تلتقي بالهاء في الاتجاهين. */
    public function test_taa_marbuta_and_haa_meet(): void
    {
        $this->ticket('SB-T-1', 'مطالبة بمستحقات');

        $this->assertSame(['SB-T-1'], $this->search('مطالبه'));
        $this->assertSame(['SB-T-1'], $this->search('مطالبة'));
    }

    /**
     * **و`%` لا يُرجع الجدول كلّه.** كانت الإبرة تُحقن خامّاً في `LIKE`، فمحرف البدل
     * يطابق كلّ شيء — ويقرأ المستخدم نتيجةً تامّةً على بحثٍ لا يطابق صفّاً.
     */
    public function test_a_percent_sign_is_a_literal_not_a_wildcard(): void
    {
        $this->ticket('SB-P-1', 'خصم 50% على الأتعاب');
        $this->ticket('SB-P-2', 'موضوع آخر تماماً');

        $this->assertSame(['SB-P-1'], $this->search('50%'));
        $this->assertSame([], $this->search('%%%'), 'محارف بدلٍ خالصة لا تطابق شيئاً');
    }

    /** و`_` كذلك — كان يطابق أيّ محرفٍ واحد. */
    public function test_an_underscore_is_a_literal(): void
    {
        $this->ticket('SB-U-1', 'مرجع REF_9 المرفق');
        $this->ticket('SB-U-2', 'مرجع REFX9 المرفق');

        $this->assertSame(['SB-U-1'], $this->search('REF_9'));
    }

    // ── التوأم في الواجهة ──

    /** ونسخة الواجهة تحمل جدول التطبيع نفسه — وإلّا اختلف بحثُ الخادم عن بحث المتصفّح. */
    public function test_the_browser_twin_shares_the_same_folding_table(): void
    {
        $lib = (string) file_get_contents(resource_path('js/lib/employee-data.ts'));

        $this->assertStringContainsString('export function foldSearch', $lib);
        $this->assertStringContainsString('export function matchesSearch', $lib);

        foreach (['٢' => '2', 'أ' => 'ا', 'ة' => 'ه', 'ى' => 'ي'] as $from => $to) {
            $this->assertStringContainsString("'{$from}': '{$to}'", $lib, "جدول الواجهة ينقصه {$from}");
            $this->assertSame($to, SearchText::FOLD[$from], "جدول الخادم ينقصه {$from}");
        }
    }
}
