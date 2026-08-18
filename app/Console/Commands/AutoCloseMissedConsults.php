<?php

namespace App\Console\Commands;

use App\Events\ConsultStatusBroadcast;
use App\Models\Consult;
use App\Support\Live;
use App\Support\Notify;
use Illuminate\Console\Command;

/**
 * حسم الاستشارات الفائتة آلياً — تثبيت للقاعدة لا مصدرًا للعرض (isMissed يعرضها فوراً بلا cron):
 * «بانتظار الجلسة» التي مضى على موعدها 12 ساعة دون انعقاد ⇒ «لم تُعقد»/«لم يحضر» + بثّ وإشعار.
 * كانت الفجوة الأكبر: أمر الاجتماعات يحسم اجتماعاته ولا أحد يحسم الاستشارات فتتراكم عالقة.
 */
class AutoCloseMissedConsults extends Command
{
    protected $signature = 'consults:auto-close-missed';

    protected $description = 'وسم الاستشارات التي فات موعدها دون انعقاد «لم يحضر» تلقائياً';

    public function handle(): int
    {
        $count = 0;
        $missed = Consult::where('session', 'بانتظار الجلسة')
            ->where('status', '!=', 'ملغاة')
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now()->subHours(12))
            ->get();

        foreach ($missed as $consult) {
            $consult->session = 'لم تُعقد';
            $consult->status = 'لم يحضر';
            $consult->logAudit('النظام', 'الجلسة', 'بانتظار الجلسة', 'لم يحضر (حسم آلي)');
            $consult->save();

            Live::push(new ConsultStatusBroadcast($consult));
            Notify::send($consult->user_id, 'clock', 't-red', "لم تُعقد جلسة استشارتك ({$consult->ref}) في موعدها. يمكنك التواصل مع المكتب لإعادة الجدولة.");
            $count++;
        }

        $this->info("تم حسم {$count} استشارة فائتة.");

        return self::SUCCESS;
    }
}
