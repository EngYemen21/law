<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **مفتاحُ الهروب يُغلق طبقةً واحدة — ومستمعُه واحدٌ في الواجهة كلّها.**
 *
 * كان كلُّ درجٍ وكلُّ نافذة يسجّل مستمعَه على `document`، فضغطةٌ واحدة تُغلق نافذةَ
 * التأكيد **والدرجَ تحتها معاً** — يفقد المستخدم موضعه ويعيد فتح الملفّ من الجدول.
 * رُصد حيّاً في درج استشارة المحامي (2026-09-25) فورَ استبدال نوافذ المتصفّح الأصليّة:
 * تلك كانت تبتلع المفتاح، فلم يظهر العطل قبلها إلّا في درجٍ واحد عولج محلّيّاً.
 *
 * فالعلاج مكدّسٌ واحد في `Modal.tsx` (‏`useEscapeLayer`) يستدعي أعلى طبقةٍ فقط. وهذا
 * الحارس يمنع أن يعود مستمعٌ خاصّ يتجاوزه.
 */
class EscapeClosesOneLayerTest extends TestCase
{
    private const HOME = 'resources/js/components/babylon/Modal.tsx';

    public function test_only_the_shared_stack_listens_for_escape(): void
    {
        $root = base_path('resources/js');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        $scanned = 0;
        $hits = [];

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                continue;
            }

            $scanned++;
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));

            if ($relative === self::HOME) {
                continue;
            }

            $lines = preg_split('/\r?\n/', (string) file_get_contents($file->getPathname())) ?: [];

            foreach ($lines as $i => $line) {
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                if (preg_match("/key\s*[!=]==?\s*['\"]Escape['\"]/", $line)) {
                    $hits[] = "{$relative}:".($i + 1).' → '.trim($line);
                }
            }
        }

        $this->assertGreaterThan(100, $scanned, 'لم تُقرأ ملفّات الواجهة — تحقّق من المسار.');
        $this->assertSame(
            [],
            $hits,
            "مستمعُ هروبٍ خاصّ يتجاوز المكدّس المشترك — استعمل useEscapeLayer:\n".implode("\n", $hits)
        );
    }

    /** ولا يمرّ الحارس لو حُذف المكدّس نفسه. */
    public function test_the_shared_stack_exists_and_modal_uses_it(): void
    {
        $modal = (string) file_get_contents(base_path(self::HOME));

        $this->assertStringContainsString('export function useEscapeLayer', $modal);
        $this->assertMatchesRegularExpression('/useEscapeLayer\(open,\s*onClose\)/', $modal, 'Modal لا يسجّل نفسه طبقةً في المكدّس.');
    }
}
