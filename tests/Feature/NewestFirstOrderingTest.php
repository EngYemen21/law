<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CaseDocument;
use App\Models\Execution;
use App\Models\ExecutionDocument;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **الجديد يظهر في أوّل صفوف الجدول** (مطلب المالك 2026-09-13).
 *
 * مُسحت سبعٌ وسبعون قائمةً في التطبيق، وأغلبها `latest('id')` سليم. وهذه الحالات السبع
 * كانت تخالف — لكلٍّ علّتها:
 *
 * ١. `Admin\LawyerController` كان `orderBy('id')` تصاعديّاً، وجدولُ الموظّفين المجاور له
 *    `orderByDesc('id')`: شاشتان متجاورتان بسلوكين متعاكسين.
 * ٢. «نبض العمليات» كان يفرز بـ`$item['id']` وهو نصٌّ ببادئة (`'t-12'`) — فرزٌ معجميّ.
 * ٣. «نشاط حديث» كان يفرز بـ`diffForHumans()` — نصٌّ عربيّ لا زمن.
 * ٤. «آخر نشاط» كان بلا فرزٍ إطلاقاً ثمّ `take(8)` على عشرة عناصر.
 * ٥. جدولا المستندات المجمَّعان كانا يرصّان المصادر تِباعاً بلا دمجٍ زمنيّ.
 * ٦ و٧. مستندات القضيّة والتنفيذ كانتا تصاعديّتين في المصدر.
 *
 * وما بقي تصاعديّاً فبحقّ: المحادثات وسجلّات الإجراءات ودفعات خطّة التقسيط.
 */
class NewestFirstOrderingTest extends TestCase
{
    use RefreshDatabase;

    /** يُسقط التعليقات ويُبقي الكود — التعليقُ يقتبس العطل القديم عمداً، والمقيس هو الكود. */
    private static function code(string $path): string
    {
        $body = (string) file_get_contents($path);
        $body = preg_replace('#/\*.*?\*/#s', ' ', $body) ?? $body;

        return preg_replace('#^\s*//.*$#m', ' ', $body) ?? $body;
    }

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    // ── ١ ──

    /** المحامي المضاف حديثاً في أوّل صفّ، كجدول الموظّفين المجاور. */
    public function test_a_newly_added_lawyer_appears_in_the_first_row(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $first = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المحامي الأقدم']);
        $latest = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المحامي الأحدث']);

        $this->assertTrue($latest->id > $first->id);

        $this->actingAs($admin)->get(route('admin.lawyers'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('lawyers.0.name', 'المحامي الأحدث'));
    }

    /** والشاشتان المتجاورتان تتّفقان — لا ترتيبان متعاكسان لكيانٍ واحد. */
    public function test_the_lawyers_and_staff_tables_agree_on_direction(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        User::factory()->create(['role' => Role::Lawyer, 'name' => 'أقدم']);
        $newest = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أحدث']);

        $this->actingAs($admin)->get(route('admin.staff'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('staff.0.name', $newest->name));
    }

    // ── ٦ و٧ ──

    /** مستند القضيّة المرفوع الآن في أوّل القائمة — للأدوار الثلاثة لا للمحامي وحده. */
    public function test_the_newest_case_document_comes_first(): void
    {
        $case = LegalCase::create([
            'user_id' => $this->client()->id, 'number' => 'CASE-DOC-'.uniqid(),
            'type' => 'تجاري', 'status' => 'قيد النظر', 'tone' => 'b-blue',
        ]);
        CaseDocument::create(['case_id' => $case->id, 'name' => 'الأقدم', 'meta' => 'PDF', 'path' => 'case-docs/1/a.pdf']);
        CaseDocument::create(['case_id' => $case->id, 'name' => 'الأحدث', 'meta' => 'PDF', 'path' => 'case-docs/1/b.pdf']);

        $this->assertSame('الأحدث', $case->fresh()->documents->first()->name);
    }

    /** ومستند ملفّ التنفيذ كذلك — بينما إجراءاته تبقى خطّاً زمنيّاً تصاعديّاً. */
    public function test_the_newest_execution_document_comes_first_but_procedures_stay_chronological(): void
    {
        $exec = Execution::create([
            'user_id' => $this->client()->id, 'number' => 'EXE-DOC-'.uniqid(),
            'subject' => 'تنفيذ', 'status' => 'قيد التنفيذ', 'tone' => 'b-blue', 'stage' => 8,
        ]);
        ExecutionDocument::create(['execution_id' => $exec->id, 'label' => 'الأقدم', 'status' => 'مطلوب']);
        ExecutionDocument::create(['execution_id' => $exec->id, 'label' => 'الأحدث', 'status' => 'مطلوب']);

        $exec->procedures()->create(['title' => 'الإجراء الأوّل', 'type' => 'إجراء', 'detail' => '', 'status' => 'منفّذ']);
        $exec->procedures()->create(['title' => 'الإجراء الثاني', 'type' => 'إجراء', 'detail' => '', 'status' => 'منفّذ']);

        $fresh = $exec->fresh();
        $this->assertSame('الأحدث', $fresh->documents->first()->label);
        $this->assertSame('الإجراء الأوّل', $fresh->procedures->first()->title, 'السجلّ الزمنيّ يبقى تصاعديّاً');
    }

    // ── ٢ و٣ و٤: المرساة الزمنيّة ──

    /**
     * الفرز يقوم على مرساةٍ زمنيّة لا على نصّ. كان `'t-12'` يُفرز معجميّاً (‏`t-9` فوق
     * `t-10`)، و`diffForHumans()` يُفرز أبجديّاً، و«آخر نشاط» بلا فرزٍ أصلاً.
     */
    public function test_activity_feeds_sort_on_a_real_timestamp_not_on_text(): void
    {
        foreach ([
            app_path('Services/AdminDashboardService.php'),
            app_path('Http/Controllers/DashboardController.php'),
        ] as $file) {
            $body = self::code($file);

            $this->assertStringNotContainsString("sortByDesc(fn (\$item) => \$item['id'])", $body);
            $this->assertStringNotContainsString("sortByDesc('time')", $body);
            $this->assertStringContainsString("sortByDesc('at')", $body, basename($file).' يفرز بمرساةٍ زمنيّة');
        }
    }

    /** وجداول المستندات المجمَّعة تُدمج زمنيّاً قبل الاقتطاع لا تُرصّ تِباعاً. */
    public function test_merged_document_tables_are_sorted_before_being_shown(): void
    {
        foreach ([
            app_path('Http/Controllers/DocumentController.php'),
            app_path('Http/Controllers/Admin/ClientController.php'),
        ] as $file) {
            $this->assertStringContainsString(
                "sortByDesc('at')",
                self::code($file),
                basename($file).' يدمج المصادر زمنيّاً'
            );
        }
    }
}
