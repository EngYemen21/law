<?php

namespace App\Console\Commands;

use App\Support\CatalogueBackfill;
use Illuminate\Console\Command;

/**
 * ربط السجلّات القديمة بكتالوج الأقسام — ما تفعله هجرة الملء، قابلاً للإعادة والمعاينة.
 *
 * بعد النشر: `--dry-run` يعرض ما سيُربط وما لم يُطابَق (مع عدده) دون كتابة، فيُضاف للنصوص غير
 * المطابَقة اسمٌ بديل من شاشة الإدارة ثمّ يُعاد التشغيل. يملأ الفارغ فقط؛ تكراره آمن.
 */
class BackfillLegalCatalogue extends Command
{
    protected $signature = 'catalogue:backfill {--dry-run : يعرض ما سيُربط دون كتابة}';

    protected $description = 'ربط التذاكر والقضايا والاستشارات والمحامين بكتالوج الأقسام (يملأ الفارغ فقط)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = CatalogueBackfill::run($dryRun);

        $this->info($dryRun ? 'معاينة — لم يُكتب شيء:' : 'اكتمل الربط:');

        foreach ($report as $group => $result) {
            $this->line(sprintf('• %s: رُبط %d', $group, $result['linked']));

            if ($result['unresolved'] !== []) {
                $this->table(['نصٌّ لم يُطابَق', 'العدد'], collect($result['unresolved'])
                    ->map(fn (int $count, string $text) => [$text, $count])
                    ->values()
                    ->all());
            }
        }

        return self::SUCCESS;
    }
}
