<?php

namespace Tests\Feature;

use App\Models\LegalSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `ai:sync-sources` — مزامنة ملفّات المصادر مع الجدول في كلّ نشر.
 *
 * الصفوف هنا **مُصطنعة** بأسماء أنظمة وهميّة (كقاعدة `LegalKnowledgeTest`): المطلوب إثباتُ السياسة
 * لا المحتوى — تكرارٌ بلا أثر، ونصٌّ لا يُستبدل بلا إصدار، ومعتمدٌ لا يُخفَّض، وملفٌّ معطوب لا يكتب شيئاً.
 */
class LegalSourceSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legal-sources-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function row(string $ref, array $overrides = []): array
    {
        return array_merge([
            'ref' => $ref,
            'system_name' => 'نظام تجريبيّ للمزامنة',
            'article_no' => '1',
            'title' => 'باب تجريبيّ',
            'text' => 'نصّ تجريبيّ لا يمثّل مادّة نظاميّة حقيقيّة.',
            'jurisdiction' => 'السعودية',
            'domain' => null,
            'version' => 'إصدار 1',
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'source_owner' => 'جهة اختبار',
            'source_url' => 'https://example.test/law',
            'usage_scope' => 'اختبار',
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $rows */
    private function writeFile(string $name, array $rows, ?array $reviewed = null): void
    {
        File::put($this->dir.DIRECTORY_SEPARATOR.$name, json_encode(
            ['bundle' => ['system_name' => 'تجريبيّ'], 'reviewed' => $reviewed, 'sources' => $rows],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
    }

    private function sync(int $exit = 0): void
    {
        $this->artisan('ai:sync-sources', ['--path' => $this->dir])->assertExitCode($exit);
    }

    public function test_running_twice_creates_no_duplicates_and_lands_as_draft(): void
    {
        $this->writeFile('a.json', [$this->row('LS-TST-1'), $this->row('LS-TST-2', ['article_no' => '2'])]);

        $this->sync();
        $this->sync();

        $this->assertSame(2, LegalSource::count());
        $this->assertSame([LegalSource::STATUS_DRAFT], LegalSource::distinct()->pluck('status')->all(), 'الجديد مسودة: الاعتماد فعلٌ بشريّ');
    }

    public function test_changed_text_needs_a_new_version_and_the_whole_run_is_refused_otherwise(): void
    {
        $this->writeFile('a.json', [$this->row('LS-TST-1')]);
        $this->sync();

        // النصّ تغيّر والإصدار نفسه ⇒ لا كتابة لأيّ صفّ، ولا حتى الجديد
        $this->writeFile('a.json', [$this->row('LS-TST-1', ['text' => 'نصّ معدَّل بلا إصدار جديد.']), $this->row('LS-TST-NEW')]);
        $this->sync(1);

        $this->assertSame('نصّ تجريبيّ لا يمثّل مادّة نظاميّة حقيقيّة.', LegalSource::where('ref', 'LS-TST-1')->value('text'));
        $this->assertFalse(LegalSource::where('ref', 'LS-TST-NEW')->exists(), 'المزامنة كلّها أو لا شيء');

        // مع إصدار جديد يُحدَّث، والحالة لا تُمسّ
        $this->writeFile('a.json', [$this->row('LS-TST-1', ['text' => 'نصّ معدَّل.', 'version' => 'إصدار 2'])]);
        $this->sync();

        $row = LegalSource::where('ref', 'LS-TST-1')->first();
        $this->assertSame('نصّ معدَّل.', $row->text);
        $this->assertSame('إصدار 2', $row->version);
        $this->assertSame(LegalSource::STATUS_DRAFT, $row->status);
    }

    public function test_an_approved_row_is_never_downgraded_nor_silently_rewritten(): void
    {
        $this->writeFile('a.json', [$this->row('LS-TST-1')]);
        $this->sync();
        LegalSource::where('ref', 'LS-TST-1')->update(['status' => LegalSource::STATUS_APPROVED, 'legal_review_at' => '2026-01-01']);

        // الملفّ نفسه مرّةً أخرى: لا يمسّ الاعتماد
        $this->sync();
        $this->assertSame(LegalSource::STATUS_APPROVED, LegalSource::where('ref', 'LS-TST-1')->value('status'));

        // نصٌّ جديد بإصدارٍ جديد بلا شهادة: يُعلَّق — يبقى المعتمد على نصّه المعتمد
        $this->writeFile('a.json', [$this->row('LS-TST-1', ['text' => 'نصّ لم يراجعه أحد.', 'version' => 'إصدار 2'])]);
        $this->sync();

        $row = LegalSource::where('ref', 'LS-TST-1')->first();
        $this->assertSame(LegalSource::STATUS_APPROVED, $row->status);
        $this->assertSame('نصّ تجريبيّ لا يمثّل مادّة نظاميّة حقيقيّة.', $row->text, 'نصٌّ لم يُراجَع لا يوضع تحت لافتة «معتمد»');
    }

    public function test_a_reviewed_file_lands_approved_only_for_rows_it_vouches_for(): void
    {
        $this->writeFile('reviewed.json', [
            $this->row('LS-TST-1', ['approved' => true]),
            $this->row('LS-TST-2', ['approved' => false]),
        ], ['by' => 'الإدارة العليا', 'at' => '2026-08-30']);

        $this->sync();

        $approved = LegalSource::where('ref', 'LS-TST-1')->first();
        $this->assertSame(LegalSource::STATUS_APPROVED, $approved->status);
        $this->assertSame('2026-08-30', $approved->legal_review_at->format('Y-m-d'));
        $this->assertSame(LegalSource::STATUS_DRAFT, LegalSource::where('ref', 'LS-TST-2')->value('status'));
    }

    public function test_approved_without_a_reviewed_block_is_a_malformed_file(): void
    {
        $this->writeFile('a.json', [$this->row('LS-TST-1', ['approved' => true])]);

        $this->sync(1);

        $this->assertSame(0, LegalSource::count());
    }

    public function test_a_malformed_file_fails_loudly_before_any_write(): void
    {
        $this->writeFile('a-good.json', [$this->row('LS-TST-1')]);
        $this->writeFile('b-bad.json', [
            $this->row('LS-TST-2', ['source_owner' => '']),                   // مالكٌ مجهول
            $this->row('LS-TST-3', ['text' => '<p>بقايا صفحة</p>']),          // بقايا HTML
            $this->row('LS-TST-4', ['domain' => 'مجالٌ لا قسم له']),         // لن يسترجعه LegalKnowledge
            $this->row('LS-TST-5', ['effective_to' => '2019-01-01']),       // ينتهي قبل أن يبدأ
        ]);
        File::put($this->dir.DIRECTORY_SEPARATOR.'c-broken.json', '{"sources": [');

        $this->sync(1);

        $this->assertSame(0, LegalSource::count(), 'ملفٌّ معطوب لا يترك مصادر ناقصة بصمت');
    }

    public function test_a_ref_shipped_in_two_files_is_refused(): void
    {
        $this->writeFile('a.json', [$this->row('LS-TST-1')]);
        $this->writeFile('b.json', [$this->row('LS-TST-1')]);

        $this->sync(1);

        $this->assertSame(0, LegalSource::count());
    }
}
