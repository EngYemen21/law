<?php

namespace App\Console\Commands;

use App\Models\LegalSource;
use Illuminate\Console\Command;

/**
 * إدخال مصادر قانونيّة معتمدة من ملفّ JSON يجهّزه الفريق القانونيّ.
 *
 * **لا يُملأ هذا الجدول برمجياً ولا من ذاكرة نموذج.** هذا الأمر مسارُ إدخالٍ محروس
 * لا مصدرُ محتوى: يرفض أي مادّة تنقصها بياناتها الحاكمة، فلا يدخل النظامَ نصٌّ
 * مجهول المالك أو السريان.
 *
 * وكل ما يدخل يُوسم **«مسودة»** مهما قال الملفّ: الاعتماد فعلٌ بشريّ منفصل داخل
 * النظام، ولا يُمنح بمجرّد تشغيل أمر في الطرفيّة.
 *
 * صيغة الملفّ: مصفوفة كائنات، لكلٍّ منها الحقول الإلزاميّة أدناه.
 */
class ImportLegalSources extends Command
{
    protected $signature = 'ai:import-sources {file : مسار ملفّ JSON} {--dry-run : افحص بلا إدخال}';

    protected $description = 'إدخال مصادر قانونيّة معتمدة (تُوسَم مسودة بانتظار اعتماد محامٍ)';

    /** بلا هذه الحقول لا يكون النصّ مصدراً — هو نصّ مجهول. */
    private const REQUIRED = ['ref', 'system_name', 'text', 'source_owner', 'effective_from'];

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("الملفّ غير موجود: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows)) {
            $this->error('الملفّ ليس JSON صالحاً (يُتوقَّع مصفوفة كائنات).');

            return self::FAILURE;
        }

        $accepted = [];
        $rejected = 0;

        foreach ($rows as $index => $row) {
            $missing = array_values(array_filter(
                self::REQUIRED,
                fn (string $field) => trim((string) ($row[$field] ?? '')) === ''
            ));

            if ($missing !== []) {
                $this->warn("[{$index}] مرفوض — حقول حاكمة ناقصة: ".implode('، ', $missing));
                $rejected++;

                continue;
            }

            if (LegalSource::where('ref', $row['ref'])->exists()) {
                $this->line("[{$index}] موجود مسبقاً: {$row['ref']} — يُتخطّى");

                continue;
            }

            $accepted[] = $row;
        }

        $this->newLine();
        $this->line('مقبول للإدخال: '.count($accepted));
        $this->line("مرفوض: {$rejected}");

        if ($this->option('dry-run')) {
            $this->warn('فحص فقط — أزِل --dry-run للإدخال.');

            return self::SUCCESS;
        }

        foreach ($accepted as $row) {
            LegalSource::create([
                'ref' => $row['ref'],
                'system_name' => $row['system_name'],
                'article_no' => $row['article_no'] ?? null,
                'title' => $row['title'] ?? null,
                'text' => $row['text'],
                'jurisdiction' => $row['jurisdiction'] ?? 'السعودية',
                'domain' => $row['domain'] ?? null,
                'version' => $row['version'] ?? null,
                'effective_from' => $row['effective_from'],
                'effective_to' => $row['effective_to'] ?? null,
                'source_owner' => $row['source_owner'],
                'source_url' => $row['source_url'] ?? null,
                'usage_scope' => $row['usage_scope'] ?? null,
                // الاعتماد لا يُمنح من الطرفيّة: يبقى مسودةً حتى يراجعه محامٍ في النظام
                'status' => LegalSource::STATUS_DRAFT,
            ]);
        }

        $this->info('أُدخل '.count($accepted).' مصدراً بحالة «مسودة».');
        $this->warn('لن يُستشهد بأيٍّ منها حتى يعتمدها محامٍ ويُسجَّل تاريخ مراجعته.');

        return self::SUCCESS;
    }
}
