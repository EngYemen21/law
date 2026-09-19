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
 * يُسقط الاختبارات إن عادت إلى الكتالوج، أو إلى كود الخادم والبذور والمسارات والإعدادات، أو إلى
 * مقارنةٍ في أيّ شاشة، أو إلى مواضعها السابقة في الواجهة.
 *
 * **نصوصٌ مطابقة ليست حالاتٍ تبقى:** «قيد التنفيذ» حالةُ تنفيذٍ حيّة (`ExecutionStatus::InProgress`)،
 * و«…للملخّص/…للمسار» حالتان حيّتان أطول (المطابقة بعلامتَي الاقتباس فلا تُلتقط)، وتسمياتٌ في
 * الواجهة (شارة «بانتظار اعتماد الإدارة» في سجلّ الاعتمادات مثلاً) وصفٌ لا مقارنة فتبقى.
 */
class RetiredStatusesStayGoneTest extends TestCase
{
    /** أيّ علامة اقتباس — مفردة أو مزدوجة أو قالب — كي لا يفلت نصٌّ بتغيير علامته. */
    private const QUOTE = '[\'"`]';

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

    /**
     * لا يكتبها الخادم ولا يقارن بها — في التطبيق والبذور والمسارات والإعدادات، بأيّ علامة
     * اقتباس. المهاجرات خارج الفحص: تاريخٌ لا يُعاد كتابته (وفيها «بانتظار اعتماد الإدارة»
     * افتراضاً حيّاً لعمود `meetings.approve` — حقلٌ آخر لا حالة تذكرة).
     */
    public function test_server_code_does_not_use_them(): void
    {
        // «قيد التنفيذ» حيّةٌ في التنفيذ وحده (الكتالوج، ومراحله، وبذور ملفّات التنفيذ)؛
        // و«مكتمل» مفتاحٌ في سجلّ تدقيق الأقساط (CaseFee) لا حالة
        $allowed = [
            'قيد التنفيذ' => [
                'app/Domain/Journey/Enums/ExecutionStatus.php', 'app/Support/ExecFlow.php',
                'database/seeders/DemoDataSeeder.php', 'database/seeders/RichDemoSeeder.php',
            ],
            'مكتمل' => ['app/Support/CaseFee.php'],
        ];

        $offenders = [];
        foreach ([app_path(), database_path(), base_path('routes'), config_path()] as $dir) {
            foreach ($this->files($dir, 'php') as $path) {
                $rel = $this->relative($path);
                if (str_starts_with($rel, 'database/migrations/')) {
                    continue;
                }
                $code = $this->withoutComments((string) file_get_contents($path));
                foreach ([...self::RETIRED_TICKET, 'مكتمل', 'pending_lawyer'] as $value) {
                    if (! preg_match('/'.self::QUOTE.preg_quote($value, '/').self::QUOTE.'/u', $code)) {
                        continue;
                    }
                    if (in_array($rel, $allowed[$value] ?? [], true)) {
                        continue;
                    }
                    // pending_lawyer حيّةٌ في حقلٍ آخر: pleading_status (مسودّة اللائحة) — ليست نتيجة الملخّص
                    if ($value === 'pending_lawyer' && ! preg_match('/result_status'.self::QUOTE.'?\s*(?:=>|===|==|,)\s*'.self::QUOTE.'pending_lawyer/', $code)) {
                        continue;
                    }
                    $offenders[] = "{$rel} ← «{$value}»";
                }
            }
        }

        $this->assertSame([], $offenders, 'حالةٌ محذوفة عادت إلى كود الخادم');
    }

    /**
     * **الواجهة كلّها لا تقارن بها.** التسميات الوصفيّة باقية (شارة «بانتظار اعتماد الإدارة» في
     * سجلّ الاعتمادات وصفٌ لا حالة)؛ المفحوص هو المقارنة وحدها — `=== '…'` أو `case '…':` —
     * لأنّها ما يُظهر زرّاً أو يخفيه. «قيد التنفيذ» حيّةٌ في التنفيذ فلا تُفحص هنا، و`pending_lawyer`
     * حيّةٌ في `pleadingStatus` فلا يُفحص إلّا ما قورن بنتيجة الملخّص.
     */
    public function test_no_screen_compares_against_them(): void
    {
        $values = implode('|', array_map(fn ($v) => preg_quote($v, '/'), ['بانتظار الدفع', 'بانتظار اعتماد النتيجة', 'بانتظار اعتماد الإدارة', 'مكتمل']));
        $offenders = [];
        foreach ([...$this->files(resource_path('js'), 'ts'), ...$this->files(resource_path('js'), 'tsx')] as $path) {
            $code = $this->withoutComments((string) file_get_contents($path));
            if (preg_match('/(?:===|!==|\bcase)\s*'.self::QUOTE.'('.$values.')'.self::QUOTE.'/u', $code, $m)) {
                $offenders[] = $this->relative($path)." ← «{$m[1]}»";
            }
            if (preg_match('/result\w*\s*(?:===|!==)\s*'.self::QUOTE.'pending_lawyer/i', $code)) {
                $offenders[] = $this->relative($path).' ← «pending_lawyer»';
            }
        }

        $this->assertSame([], $offenders, 'شاشةٌ عادت تقارن بحالةٍ محذوفة');
    }

    /** مواضعها السابقة في الواجهة خالية منها — ولو نصّاً بلا مقارنة (مفاتيح خرائط، تسميات أزرار). */
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
                $this->assertDoesNotMatchRegularExpression('/'.self::QUOTE.preg_quote($value, '/').self::QUOTE.'/u', $code, "{$file} عاد يحمل «{$value}»");
            }
        }
    }

    /** @return list<string> */
    private function files(string $dir, string $extension): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === $extension) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
    }

    /** التعليقات توثّق ما حُذف ولماذا — تُستثنى، والمقيس هو الكود. */
    private function withoutComments(string $code): string
    {
        return (string) preg_replace(['#/\*.*?\*/#s', '#(^|[^:\'"])//[^\n]*#'], ['', '$1'], $code);
    }
}
