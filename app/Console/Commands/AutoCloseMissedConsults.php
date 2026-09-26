<?php

namespace App\Console\Commands;

use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Consult\MarkNoShow;
use App\Domain\Journey\Workflow;
use App\Models\Consult;
use App\Support\SettingsRegistry;
use Illuminate\Console\Command;

/**
 * حسم الاستشارات الفائتة آلياً — تثبيت للقاعدة لا مصدرًا للعرض (isMissed يعرضها فوراً بلا cron):
 * «بانتظار الجلسة» التي مضى على موعدها مهلةُ الإعدادات (`consult_autoclose_minutes` — 12 ساعة افتراضاً) دون انعقاد ⇒ «لم تُعقد»/«لم يحضر» + بثّ وإشعار.
 * كانت الفجوة الأكبر: أمر الاجتماعات يحسم اجتماعاته ولا أحد يحسم الاستشارات فتتراكم عالقة.
 */
class AutoCloseMissedConsults extends Command
{
    // المهلة معاملٌ افتراضه من الإعدادات — كانت 12 منقوشة هنا، فتغييرها يحتاج نشرَ كود.
    // وبالدقائق كإعدادها (قرار المالك 2026-09-26) — وحدةٌ واحدة لا تحويلٌ بين المعامل والإعداد.
    protected $signature = 'consults:auto-close-missed {--minutes= : المهلة بالدقائق بعد الموعد (الافتراض من الإعدادات)}';

    protected $description = 'وسم الاستشارات التي فات موعدها دون انعقاد «لم يحضر» تلقائياً';

    public function handle(): int
    {
        $option = $this->option('minutes');
        $minutes = max(1, $option === null || $option === '' ? SettingsRegistry::int('consult_autoclose_minutes') : (int) $option);

        $count = 0;
        $missed = Consult::where('session', 'بانتظار الجلسة')
            ->where('status', '!=', 'ملغاة')
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now()->subMinutes($minutes))
            ->get();

        foreach ($missed as $consult) {
            // الانتقال نفسه الذي يسلكه زرّ الطاقم — والإشعار والبثّ في مستمعه
            try {
                Workflow::run(new MarkNoShow, $consult, null, ['automatic' => true]);
                $count++;
            } catch (TransitionDenied) {
                continue; // دورة حجزٍ أو نهاية — ليست جلسةً فائتة
            }
        }

        $this->info("تم حسم {$count} استشارة فائتة.");

        return self::SUCCESS;
    }
}
