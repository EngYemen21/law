<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Enums\TicketStatus;
use Tests\TestCase;

/**
 * **الحالات المحذوفة لا تعود** (قرار المالك 2026-09-19).
 *
 * حُذفت من التذكرة «بانتظار الدفع» و«قيد التنفيذ» و«بانتظار اعتماد النتيجة» و«بانتظار اعتماد
 * الإدارة»، ومن التنفيذ «مكتمل»، ونتيجة الملخّص `pending_lawyer` — لا يكتبها أيّ كود. هذا الحارس
 * يُسقط الاختبارات إن عادت إلى الكتالوج، أو إلى كود الخادم، أو إلى مواضعها السابقة في الواجهة.
 *
 * **نصوصٌ مطابقة ليست حالاتٍ تبقى:** «قيد التنفيذ» حالةُ تنفيذٍ حيّة (`ExecutionStatus::InProgress`)،
 * و«…للملخّص/…للمسار» حالتان حيّتان أطول، وتسمياتٌ في الواجهة (شارة «بانتظار اعتماد الإدارة» في
 * سجلّ الاعتمادات مثلاً) وصفٌ لا مقارنة — فالحارس يفحص مواضعها السابقة بالاسم لا المشروع كلّه.
 */
class RetiredStatusesStayGoneTest extends TestCase
{
    private const RETIRED_TICKET = ['بانتظار الدفع', 'قيد التنفيذ', 'بانتظار اعتماد النتيجة', 'بانتظار اعتماد الإدارة'];

    public function test_the_catalogues_no_longer_hold_them(): void
    {
        foreach (self::RETIRED_TICKET as $status) {
            $this->assertNull(TicketStatus::tryFrom($status), "«{$status}» عادت إلى كتالوج التذكرة");
        }
        $this->assertNull(ExecutionStatus::tryFrom('مكتمل'), '«مكتمل» عادت إلى كتالوج التنفيذ');
        // الحيّة المشابهة باقية
        $this->assertSame('قيد التنفيذ', ExecutionStatus::InProgress->value);
        $this->assertNotNull(TicketStatus::tryFrom('بانتظار اعتماد الإدارة للملخّص'));
        $this->assertNotNull(TicketStatus::tryFrom('بانتظار اعتماد الإدارة للمسار'));
    }

    /** لا يكتبها الخادم ولا يقارن بها — خارج مواضع التنفيذ الحيّة المعلنة. */
    public function test_server_code_does_not_use_them(): void
    {
        // «قيد التنفيذ» حيّةٌ في التنفيذ وحده؛ و«مكتمل» مفتاحٌ في سجلّ تدقيق الأقساط (CaseFee) لا حالة
        $allowed = [
            'قيد التنفيذ' => ['app/Domain/Journey/Enums/ExecutionStatus.php', 'app/Support/ExecFlow.php'],
            'مكتمل' => ['app/Support/CaseFee.php'],
        ];

        $offenders = [];
        foreach ($this->phpFiles(app_path()) as $path) {
            $rel = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
            $code = $this->withoutComments((string) file_get_contents($path));
            foreach ([...self::RETIRED_TICKET, 'مكتمل', 'pending_lawyer'] as $value) {
                if (! str_contains($code, "'{$value}'")) {
                    continue;
                }
                if (in_array($rel, $allowed[$value] ?? [], true)) {
                    continue;
                }
                // pending_lawyer حيّةٌ في حقلٍ آخر: pleading_status (مسودّة اللائحة) — ليست نتيجة الملخّص
                if ($value === 'pending_lawyer' && ! preg_match("/result_status['\"]?\s*(?:=>|===|==|,)\s*'pending_lawyer'/", $code)) {
                    continue;
                }
                $offenders[] = "{$rel} ← «{$value}»";
            }
        }

        $this->assertSame([], $offenders, 'حالةٌ محذوفة عادت إلى كود الخادم');
    }

    /** مواضعها السابقة في الواجهة خالية منها. */
    public function test_their_former_ui_spots_are_clean(): void
    {
        $spots = [
            'js/lib/chat.ts' => self::RETIRED_TICKET,
            'js/pages/employee/ticketchat.tsx' => ['بانتظار الدفع', 'بانتظار اعتماد النتيجة', 'بانتظار سداد العميل'],
            'js/pages/tickets.tsx' => ['بانتظار الدفع', 'سداد الرسوم'],
            'js/pages/lawyer/ticketchat.tsx' => ['pending_lawyer', 'اعتماد ملخص الجلسة ورفعه للإدارة'],
        ];

        foreach ($spots as $file => $values) {
            $code = $this->withoutComments((string) file_get_contents(resource_path($file)));
            foreach ($values as $value) {
                $this->assertStringNotContainsString("'{$value}'", $code, "{$file} عاد يحمل «{$value}»");
            }
        }
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    /** التعليقات توثّق ما حُذف ولماذا — تُستثنى، والمقيس هو الكود. */
    private function withoutComments(string $code): string
    {
        return (string) preg_replace(['#/\*.*?\*/#s', '#(^|[^:\'"])//[^\n]*#'], ['', '$1'], $code);
    }
}
