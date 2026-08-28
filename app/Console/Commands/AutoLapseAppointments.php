<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use Illuminate\Console\Command;

/**
 * حسم المواعيد التي فات وقتها — الكيان الوحيد الذي كان بلا أمر حسم.
 *
 * الاجتماعات والدعوات (zoom:auto-close-missed) والاستشارات (consults:auto-close-missed)
 * والجلسات (hearings:auto-lapse) تُحسم دورياً؛ أمّا المواعيد فحالتها تُشتقّ **لحظياً** في
 * Appointment::liveState() ولا تُكتب أبداً. فالعميل يرى الحالة صحيحة، لكن الأعمدة المخزَّنة
 * (status · tone · when_kind) تبقى «مؤكد/up» إلى الأبد — وDashboardController يقرأ
 * when_kind مباشرةً، وأي تقرير أو ترشيح يعتمد العمود الخام يرى ماضياً على أنه قادم.
 *
 * الحسم يكتب ما تشتقّه liveState() نفسها — مصدر واحد للحقيقة، لا منطق ثانٍ يتباعد عنه.
 */
class AutoLapseAppointments extends Command
{
    protected $signature = 'appointments:auto-lapse';

    protected $description = 'حسم حالة المواعيد التي انقضى وقتها (تم الحضور / لم يحضر / ملغي)';

    public function handle(): int
    {
        $settled = 0;

        // المرشّحون: ما لم يُحسم بعد (when_kind ما زال up/today) وله موعد فعليّ مضى.
        // الحدّ 500 لكل تشغيل: الأمر يعمل كل ربع ساعة فلا حاجة لكنس الأرشيف كلّه دفعة واحدة.
        Appointment::with('consult')
            ->whereIn('when_kind', ['up', 'today'])
            ->whereNotNull('starts_at')
            ->where('starts_at', '<', now())
            ->orderBy('starts_at')
            ->limit(500)
            ->get()
            ->each(function (Appointment $a) use (&$settled): void {
                [$when, $status, $tone] = $a->liveState();

                // 'up' هنا يعني جلسة ما زالت جارية ضمن سقفها — حالة عابرة لا تُكتب كنهائية
                if ($when !== 'past') {
                    return;
                }

                $a->update(['when_kind' => 'past', 'status' => $status, 'tone' => $tone]);
                $settled++;
            });

        $this->info("حُسمت {$settled} موعداً.");

        return self::SUCCESS;
    }
}
