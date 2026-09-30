<?php

namespace App\Console\Commands;

use App\Support\AppEnvironment;
use App\Support\EnvironmentAudit;
use Illuminate\Console\Command;

/**
 * `php artisan env:check` — يطبع مخالفات إعداد البيئة (`EnvironmentAudit`) ويخرج بـ1 إن وُجدت مخالفةٌ مُفشِلة.
 * يُشغَّل بعد كلّ نشرٍ على الإنتاج (`DEPLOYMENT_AR.md`) وبعد أيّ تعديلٍ على `.env`.
 */
class EnvironmentCheck extends Command
{
    protected $signature = 'env:check';

    protected $description = 'يفحص إعداد البيئة (إنتاج أو تجربة) ويسرد المخالفات';

    public function handle(): int
    {
        $env = (string) app()->environment();
        $this->info(sprintf('البيئة: %s (%s)', $env, AppEnvironment::isSandbox() ? 'صندوق تجربة' : 'إنتاج'));

        $findings = EnvironmentAudit::findings();

        if ($findings === []) {
            $this->info('✓ لا مخالفات.');

            return self::SUCCESS;
        }

        $this->table(['', 'المتغيّر', 'المخالفة'], array_map(
            fn (array $f) => [$f['level'] === 'fail' ? '✗' : '!', $f['key'], $f['message']],
            $findings,
        ));

        $failures = count(array_filter($findings, fn (array $f) => $f['level'] === 'fail'));

        if ($failures > 0) {
            $this->error("{$failures} مخالفة تمنع اعتماد هذا الإعداد.");

            return self::FAILURE;
        }

        $this->warn('تنبيهاتٌ فقط — الإعداد مقبول.');

        return self::SUCCESS;
    }
}
