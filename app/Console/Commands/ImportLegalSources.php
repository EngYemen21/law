<?php

namespace App\Console\Commands;

use App\Models\LegalSource;
use App\Services\Ai\LegalSourceBundle;
use Illuminate\Console\Command;

/**
 * إدخال مصادر قانونيّة معتمدة من ملفّ JSON يجهّزه الفريق القانونيّ.
 *
 * **لا يُملأ هذا الجدول برمجياً ولا من ذاكرة نموذج.** هذا الأمر مسارُ إدخالٍ محروس
 * لا مصدرُ محتوى: يرفض أي ملفّ تنقص مادّةً فيه بياناتها الحاكمة، فلا يدخل النظامَ نصٌّ
 * مجهول المالك أو السريان. والفحص نفسه الذي يحرس `ai:sync-sources` (`LegalSourceBundle`).
 *
 * وكل ما يدخل يُوسم **«مسودة»** مهما قال الملفّ — حتى لو حمل شهادة اعتماد: الاعتماد فعلٌ بشريّ
 * منفصل داخل النظام، ولا يُمنح بمجرّد تشغيل أمر في الطرفيّة. (الملفّات المشحونة مع الشيفرة في
 * `database/legal-sources/` تُزامَن بـ`ai:sync-sources` في النشر.)
 *
 * صيغة الملفّ: مصفوفة كائنات، أو كائن `{sources: [...]}` — انظر `LegalSourceBundle`.
 */
class ImportLegalSources extends Command
{
    protected $signature = 'ai:import-sources {file : مسار ملفّ JSON} {--dry-run : افحص بلا إدخال}';

    protected $description = 'إدخال مصادر قانونيّة معتمدة (تُوسَم مسودة بانتظار اعتماد محامٍ)';

    public function handle(): int
    {
        $bundle = LegalSourceBundle::fromFile((string) $this->argument('file'));

        if (! $bundle->isValid()) {
            foreach ($bundle->errors as $error) {
                $this->error($error);
            }
            $this->error('رُفض الملفّ كاملاً — لم يُدخَل شيء.');

            return self::FAILURE;
        }

        $accepted = [];
        foreach ($bundle->rows as $index => $row) {
            if (LegalSource::where('ref', $row['ref'])->exists()) {
                $this->line("[{$index}] موجود مسبقاً: {$row['ref']} — يُتخطّى");

                continue;
            }
            $accepted[] = $row;
        }

        $this->newLine();
        $this->line('مقبول للإدخال: '.count($accepted));

        if ($this->option('dry-run')) {
            $this->warn('فحص فقط — أزِل --dry-run للإدخال.');

            return self::SUCCESS;
        }

        foreach ($accepted as $row) {
            LegalSource::create(array_merge(
                array_intersect_key($row, array_flip(LegalSourceBundle::FIELDS)),
                [
                    'jurisdiction' => $row['jurisdiction'] ?? 'السعودية',
                    // الاعتماد لا يُمنح من الطرفيّة: يبقى مسودةً حتى يراجعه محامٍ في النظام
                    'status' => LegalSource::STATUS_DRAFT,
                ],
            ));
        }

        $this->info('أُدخل '.count($accepted).' مصدراً بحالة «مسودة».');
        $this->warn('لن يُستشهد بأيٍّ منها حتى يعتمدها محامٍ ويُسجَّل تاريخ مراجعته.');

        return self::SUCCESS;
    }
}
