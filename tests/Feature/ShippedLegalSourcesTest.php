<?php

namespace Tests\Feature;

use App\Models\LegalSource;
use App\Services\Ai\LegalKnowledge;
use App\Services\Ai\LegalSourceBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الملفّات المشحونة فعلاً في `database/legal-sources/` — هي ما يصل الخادم في النشر.
 *
 * بخلاف `LegalKnowledgeTest` (مصادر مُصطنعة)، هنا تُقرأ الملفّات الحقيقيّة: حارسٌ على أنّ كلّ ملفٍّ
 * يقبله المستورِد، وأنّ مجالاتها يطابقها الاسترجاع فعلاً، وأنّ سريان نظامَي التنفيذ يتعاقب بلا فجوة.
 * النصوص نفسها لا تُكتب في الاختبار — تُقرأ من الملفّات.
 */
class ShippedLegalSourcesTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<LegalSourceBundle> */
    private function bundles(): array
    {
        $files = glob(database_path('legal-sources/*.json')) ?: [];
        sort($files);

        return array_map(fn (string $f) => LegalSourceBundle::fromFile($f), $files);
    }

    /** @return array<string, LegalSourceBundle> */
    private function byName(): array
    {
        $out = [];
        foreach ($this->bundles() as $b) {
            $out[$b->name()] = $b;
        }

        return $out;
    }

    private function approveSystem(string $system): void
    {
        LegalSource::where('system_name', $system)->update(['status' => LegalSource::STATUS_APPROVED, 'legal_review_at' => '2026-09-26']);
    }

    public function test_every_shipped_file_is_valid_for_the_importer(): void
    {
        $bundles = $this->bundles();
        $this->assertNotEmpty($bundles);

        $refs = [];
        foreach ($bundles as $bundle) {
            $this->assertSame([], $bundle->errors, $bundle->name());
            $this->assertNotEmpty($bundle->rows, $bundle->name());

            foreach ($bundle->rows as $row) {
                $refs[] = $row['ref'];
                $this->assertNotSame('', trim($row['text']), $row['ref']);
                // سريانٌ معقول: لا تاريخ مقلوب ولا سنة مختلَقة
                $this->assertGreaterThanOrEqual('1990-01-01', $row['effective_from'], $row['ref']);
                $this->assertLessThanOrEqual('2030-12-31', $row['effective_from'], $row['ref']);
            }
        }

        $this->assertSame(count($refs), count(array_unique($refs)), 'المعرّف مفتاح المزامنة — لا يتكرّر بين ملفّين');
    }

    /** الأنظمة المستوردة حديثاً تنتظر اعتماد محامٍ — لا تحمل شهادة اعتماد في الملفّ. */
    public function test_only_the_bundles_approved_in_development_carry_a_review_certificate(): void
    {
        $vouched = collect($this->bundles())
            ->filter(fn (LegalSourceBundle $b) => $b->reviewed !== null)
            ->map->name()->values()->all();

        $this->assertSame(['civil-transactions.json', 'enforcement-new.json'], $vouched);
    }

    /**
     * نظام التنفيذ (1433هـ) ينتهي في اليوم السابق لنفاذ الجديد — إلّا ما استثناه قرار مجلس الوزراء
     * (الحجز التحفظيّ والإعسار) فيبقى بلا نهاية حتى يُنقل.
     */
    public function test_the_old_enforcement_law_hands_over_to_the_new_one_without_a_gap(): void
    {
        $bundles = $this->byName();
        $newFrom = collect($bundles['enforcement-new.json']->rows)->pluck('effective_from')->unique()->all();
        $this->assertSame(['2026-10-28'], $newFrom);

        $old = collect($bundles['enforcement-1433.json']->rows);
        $ends = $old->pluck('effective_to')->unique()->values()->all();
        sort($ends);
        $this->assertSame([null, '2026-10-27'], $ends);

        $persisting = $old->whereNull('effective_to')->pluck('ref')->all();
        $this->assertNotEmpty($persisting);
        foreach ($old->whereNull('effective_to') as $row) {
            $this->assertStringContainsString('يستمر العمل بها', $row['usage_scope'], $row['ref']);
        }
    }

    public function test_retrieval_uses_the_new_statutes_only_once_approved(): void
    {
        $this->artisan('ai:sync-sources')->assertExitCode(0);

        // مسودّةٌ لا يُستشهد بها
        $this->assertTrue(LegalKnowledge::retrieve('القضايا العمالية', 'فصل تعسفي')->isEmpty());

        $this->approveSystem('نظام العمل');
        $labour = LegalKnowledge::retrieve('القضايا العمالية', 'فصل تعسفي')->pluck('ref');
        $this->assertNotEmpty($labour);
        $this->assertTrue($labour->every(fn ($r) => str_starts_with($r, 'LS-LABOUR-')), $labour->implode(', '));

        // الصياغة المختصرة للمجال تصل القسم نفسه
        $this->assertNotEmpty(LegalKnowledge::retrieve('عمالي', 'أجر العامل'));

        $this->approveSystem('نظام الأحوال الشخصية');
        $family = LegalKnowledge::retrieve('الأحوال الشخصية والأسرة', 'نفقة وحضانة')->pluck('ref');
        $this->assertNotEmpty($family);
        $this->assertTrue($family->every(fn ($r) => str_starts_with($r, 'LS-FAMILY-')), $family->implode(', '));
    }

    public function test_the_old_enforcement_law_is_retrieved_before_the_new_law_date_and_not_after(): void
    {
        $this->artisan('ai:sync-sources')->assertExitCode(0);
        $this->approveSystem('نظام التنفيذ (1433هـ)');

        $query = 'منع المدين من السفر وحجز أمواله';

        $before = LegalKnowledge::retrieve('التنفيذ', $query, new \DateTimeImmutable('2026-10-27'))->pluck('ref');
        $this->assertTrue($before->contains(fn ($r) => str_starts_with($r, 'LS-EXEC-OLD-')), $before->implode(', '));
        $this->assertFalse($before->contains(fn ($r) => ! str_starts_with($r, 'LS-EXEC-OLD-')), 'الجديد لم يبدأ سريانه بعد');

        $after = LegalKnowledge::retrieve('التنفيذ', $query, new \DateTimeImmutable('2026-10-28'), limit: 50)->pluck('ref');
        $stillInForce = LegalSource::where('ref', 'like', 'LS-EXEC-OLD-%')->whereNull('effective_to')->pluck('ref');
        $this->assertTrue(
            $after->filter(fn ($r) => str_starts_with($r, 'LS-EXEC-OLD-'))->every(fn ($r) => $stillInForce->contains($r)),
            'بعد نفاذ الجديد لا يبقى من القديم إلّا ما استثناه القرار: '.$after->implode(', ')
        );
        $this->assertTrue($after->contains(fn ($r) => ! str_starts_with($r, 'LS-EXEC-OLD-')), 'الجديد (المعتمد) يحلّ محلّه');
    }
}
