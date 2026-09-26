<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **لا إنهاءَ ولا إلغاءَ بلا تأكيد** (قرار المالك 2026-09-26).
 *
 * إنهاء الجلسة أو الاجتماع وإلغاؤهما وإلغاء الدعوة أفعالٌ لا يُتراجع عنها: تُغلق غرفة Zoom أو
 * تحذفها وتُبلغ العميل. فكلّ نداءٍ لها في الواجهة يسبقه تأكيدٌ من النافذة المشتركة
 * (`useConfirm` / `usePrompt` — `await ask(…)` أو `await askFor(…)` أو `await prompt(…)`) يقول الأثر، لا نافذة المتصفّح.
 *
 * فحصٌ نصّيّ: كلّ `router.post` إلى رابط إنهاءٍ/إلغاء في هذه الملفّات يسبقه — داخل الدالّة نفسها،
 * في الأسطر القريبة قبله — `await ask(` أو `await askFor(` أو `await prompt(` (`askFor` اسم `usePrompt` المعتمد في
 * المشروع كي لا يلتبس بنافذة المتصفّح `prompt(` التي يمنعها `NativeDialogsAreGoneTest`). زرٌّ جديد يُرسل بلا تأكيد يُسقط الاختبار.
 */
class EndCancelConfirmGuardTest extends TestCase
{
    /** الملفّات التي فيها أزرار إنهاءٍ/إلغاء للاستشارات والاجتماعات والدعوات. */
    private const FILES = [
        'resources/js/lib/meeting-ui.tsx',
        'resources/js/lib/consult-ui.tsx',
        'resources/js/lib/zoom-room.tsx',
        'resources/js/pages/admin/consultrecv.tsx',
        'resources/js/pages/admin/consults.tsx',
        'resources/js/pages/admin/consult-requests.tsx',
        'resources/js/pages/lawyer/consults.tsx',
    ];

    /** رابط إنهاءٍ أو إلغاء — والغرفة ترسل إلى `end.url` من عقدها (`RoomDetails::endAction`). */
    private const ENDPOINT = '~router\.post\(\s*(`[^`]*/(consults|meetings|meetreqs)/\$\{[^}]+\}/(end|cancel|cancel-request)`|end\.url)~';

    /** كم سطراً قبل الإرسال يُبحث فيها عن التأكيد — تكفي لحارسٍ أو اثنين قبله. */
    private const WINDOW = 25;

    public function test_every_end_or_cancel_request_is_sent_only_after_a_confirmation(): void
    {
        $violations = [];
        $found = 0;

        foreach (self::FILES as $path) {
            $lines = file(base_path($path), FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $i => $line) {
                if (! preg_match(self::ENDPOINT, $line) && ! (str_contains($line, 'router.post(') && isset($lines[$i + 1]) && preg_match(self::ENDPOINT, 'router.post('.trim($lines[$i + 1])))) {
                    continue;
                }
                $found++;

                $before = implode("\n", array_slice($lines, max(0, $i - self::WINDOW), min($i, self::WINDOW)));
                if (! preg_match('/await\s+(ask|askFor|prompt)\(/', $before)) {
                    $violations[] = "{$path}:".($i + 1).' — إرسال إنهاءٍ/إلغاء بلا تأكيدٍ قبله';
                }
            }

            if (str_contains(implode("\n", $lines), 'window.confirm')) {
                $violations[] = "{$path}: window.confirm — النافذة المشتركة (`useConfirm`) لا نافذة المتصفّح";
            }
        }

        // الفحص نفسه يجد ما يفحصه: الإنهاء والإلغاء في الشاشات السبع (٩ مواضع اليوم)
        $this->assertGreaterThanOrEqual(9, $found, 'تغيّر شكل النداءات — حدِّث نمط الفحص لا تُسقطه');
        $this->assertSame([], $violations, implode("\n", $violations));
    }
}
