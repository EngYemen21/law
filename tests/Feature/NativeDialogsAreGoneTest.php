<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **لا نافذةَ متصفّحٍ أصليّة في الواجهة — لا اليوم ولا في ملفٍّ جديد.**
 *
 * كانت ثمانيَ عشرةَ نافذة (`window.confirm` · `window.prompt`) في تسعة ملفّات. وليست مسألةَ
 * شكل: المتصفّح **يكتمها بعد تكرارها** فيرجع `confirm` بـ`false` صامتاً — فيبدو زرُّ «إلغاء
 * الجلسة» أو «الاعتماد النهائيّ» ميّتاً بلا رسالة. وهي فوق ذلك تُجمّد الصفحة فتقطع البثّ
 * اللحظيّ، وتخرج بالإنجليزيّة يساريّةَ الاتّجاه وسط واجهةٍ عربيّة.
 *
 * فالبديل `ConfirmDialogProvider` مصدراً واحداً، وهذا الحارس يمنع العودة: أيّ نداءٍ أصليّ
 * جديد يُسقط الاختبار باسم ملفّه وسطره.
 */
class NativeDialogsAreGoneTest extends TestCase
{
    /**
     * `window.confirm(` صريحةً، أو نداءٌ مجرّد `confirm(` لا يسبقه نقطةٌ ولا حرفُ اسم —
     * فلا تُحسب `ask(` ولا `useConfirm(` ولا `something.prompt(`.
     */
    private const NATIVE_CALL = '/(?:window\s*\.\s*(?:confirm|prompt|alert)\s*\(|(?<![\w.$])(?:confirm|prompt|alert)\s*\()/';

    /** @return list<string> */
    private function frontendFiles(): array
    {
        $root = base_path('resources/js');
        $files = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function test_no_native_browser_dialog_is_called_anywhere(): void
    {
        $files = $this->frontendFiles();

        // ولا يمرّ الحارس فراغاً إن تغيّر مسار الواجهة
        $this->assertGreaterThan(100, count($files), 'لم تُقرأ ملفّات الواجهة — تحقّق من المسار.');

        $hits = [];

        foreach ($files as $path) {
            $lines = preg_split('/\r?\n/', (string) file_get_contents($path)) ?: [];

            foreach ($lines as $i => $line) {
                // التعليقات لا تُحسب: شرحُ سببِ الحذف يذكر الاسم المحذوف
                $trimmed = ltrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                    continue;
                }

                if (preg_match(self::NATIVE_CALL, $line)) {
                    $hits[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).':'.($i + 1).' → '.trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $hits,
            "نافذةُ متصفّحٍ أصليّة في الواجهة — استعمل useConfirm/usePrompt:\n".implode("\n", $hits)
        );
    }

    /** ولا يمرّ الحارس لو حُذف البديل: المزوّد موجودٌ ومركَّبٌ في جذر التطبيق. */
    public function test_the_replacement_is_mounted_at_the_application_root(): void
    {
        $this->assertFileExists(base_path('resources/js/components/babylon/ConfirmDialog.tsx'));

        $app = (string) file_get_contents(base_path('resources/js/app.tsx'));

        $this->assertStringContainsString('ConfirmDialogProvider', $app, 'مزوّد نوافذ التأكيد غير مركَّب في جذر التطبيق.');
    }
}
