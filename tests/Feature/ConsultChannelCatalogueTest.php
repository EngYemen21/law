<?php

namespace Tests\Feature;

use App\Models\Consult;
use Tests\TestCase;

/**
 * **قنوات الاستشارة كتالوجٌ واحد على الجانبين** (2026-09-20).
 *
 * كانت القائمة مكتوبةً نصّاً في خمسة مواضع: التحقّق من المدخلات، وانتقال التسعير، وثلاث كتل
 * في شاشتَي الإدارة. وأوّل قناةٍ تُضاف أو يتغيّر اسمها تجعل الواجهة تعرض خياراً يرفضه الخادم
 * (٤٢٢ بلا سبب ظاهر للموظّف). المصدر الآن `Consult::CHANNELS` ونظيرُه `CONSULT_CHANNEL_OPTIONS`،
 * وهذا الحارس يُسقط الاختبارات إن تباعدا أو عاد التكرار النصّيّ.
 */
class ConsultChannelCatalogueTest extends TestCase
{
    private function ui(string $relative): string
    {
        $path = resource_path($relative);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_the_two_catalogues_match(): void
    {
        preg_match(
            "/export const CONSULT_CHANNEL_OPTIONS = \[(.*?)\] as const;/s",
            $this->ui('js/lib/employee-data.ts'),
            $m
        );
        $this->assertNotEmpty($m, 'تعذّر العثور على كتالوج القنوات في الواجهة.');

        preg_match_all("/'([^']+)'/u", $m[1], $values);

        $this->assertSame(Consult::CHANNELS, $values[1], 'كتالوج القنوات في الواجهة يخالف `Consult::CHANNELS`.');
    }

    /** لا قائمةَ قنواتٍ مكتوبةً نصّاً في شاشات التسعير — المنتقي يقرأ الكتالوج. */
    public function test_the_pricing_screens_read_the_catalogue(): void
    {
        foreach (['js/pages/admin/consults.tsx', 'js/pages/admin/consult-requests.tsx'] as $page) {
            $src = $this->ui($page);
            $this->assertStringContainsString('CONSULT_CHANNEL_OPTIONS', $src, "{$page} لا يقرأ كتالوج القنوات.");
            $this->assertDoesNotMatchRegularExpression(
                "/label: '(حضورية|مرئية|هاتفية)'/u",
                $src,
                "{$page} عاد يكتب قائمة القنوات نصّاً."
            );
        }
    }

    /** والخادم كذلك: التحقّق والانتقال يقرآن الثابت لا نصّاً. */
    public function test_the_server_reads_the_constant(): void
    {
        foreach ([
            'app/Http/Controllers/Staff/ConsultController.php',
            'app/Domain/Journey/Transitions/Consult/PriceConsult.php',
        ] as $file) {
            $src = (string) file_get_contents(base_path($file));
            $this->assertStringContainsString('Consult::CHANNELS', $src, "{$file} لا يقرأ كتالوج القنوات.");
            $this->assertStringNotContainsString("'حضورية', 'مرئية', 'هاتفية'", $src, "{$file} عاد يكتب القائمة نصّاً.");
        }
    }
}
