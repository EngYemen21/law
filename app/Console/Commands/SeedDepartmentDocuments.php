<?php

namespace App\Console\Commands;

use App\Support\DepartmentDocumentsSeed;
use Illuminate\Console\Command;

/**
 * زرع قوائم مستندات الأقسام من القوائم القديمة — قابلاً للمعاينة والإعادة (نظير `catalogue:backfill`).
 *
 * بعد النشر: `--dry-run` يعرض ما سيُزرع لكلّ قسم، والأنواع التي لم تُطابَق، والأقسام التي تبقى على
 * القائمة العامّة (تحتاج قرار المالك). يكتب في قسمٍ لا قائمة له فقط؛ تكراره آمن.
 */
class SeedDepartmentDocuments extends Command
{
    protected $signature = 'catalogue:seed-documents {--dry-run : يعرض ما سيُزرع دون كتابة}';

    protected $description = 'زرع قوائم المستندات المطلوبة للأقسام القانونيّة (في القسم الذي لا قائمة له فقط)';

    private const ACTIONS = [
        'seeded' => 'زُرعت',
        'would_seed' => 'ستُزرع',
        'skipped' => 'تُركت — للقسم قائمةٌ محرَّرة',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = DepartmentDocumentsSeed::run($dryRun);

        $this->info($dryRun ? 'معاينة — لم يُكتب شيء:' : 'اكتمل الزرع:');

        $this->table(['القسم', 'من النوع القديم', 'الإجراء', 'المستندات'], array_map(fn (array $row) => [
            $row['department'],
            implode('، ', $row['sources']),
            self::ACTIONS[$row['action']],
            implode('، ', array_map(fn (array $d) => $d['name'].($d['required'] ? '' : ' (اختياريّ)'), $row['documents'])),
        ], $report['departments']));

        if ($report['unresolved'] !== []) {
            $this->warn('أنواعٌ قديمة لم تُطابَق قسماً (أضف لها اسماً بديلاً ثمّ أعد التشغيل): '.implode('، ', $report['unresolved']));
        }

        if ($report['needsOwner'] !== []) {
            $this->warn('أقسامٌ تبقى على القائمة العامّة — تحتاج قرار المالك: '.implode('، ', $report['needsOwner']));
        }

        return self::SUCCESS;
    }
}
