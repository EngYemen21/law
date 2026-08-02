<?php

namespace App\Console\Commands;

use App\Mail\ConsultReminderMail;
use App\Models\Consult;
use App\Services\MailService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * تذكير بمواعيد الاستشارات القادمة عبر البريد — طبقتان مستقلّتان:
 *   • نحو 24 ساعة قبل الموعد (reminder_24h_sent_at)
 *   • نحو ساعة قبل الموعد (reminder_1h_sent_at)
 * كلّ طبقة idempotent عبر ختمها الخاصّ. رابط الجلسة المرئية يُرسَل منفصلاً قبل 5د (zoom:release-links).
 * يعمل فقط على استشارات لها starts_at (تعذّر التحليل ⇒ لا تذكير).
 */
class SendConsultReminders extends Command
{
    protected $signature = 'consults:send-reminders';

    protected $description = 'إرسال تذكيرات مواعيد الاستشارات (قبل 24 ساعة وقبل ساعة) عبر البريد';

    public function handle(MailService $mail): int
    {
        $now = now();

        // الاستشارات القادمة المحجوزة (لها موعد ولم تُعقد بعد) خلال الأفق الأقصى (24 ساعة)
        $consults = Consult::with('user')
            ->where('session', 'بانتظار الجلسة')
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->copy()->addDay())
            ->get();

        $sent = 0;

        foreach ($consults as $consult) {
            if (! $consult->user?->email) {
                continue;
            }

            $startsAt = $consult->starts_at;
            $remaining = $this->remainingLabel($now, $startsAt);

            // طبقة 24 ساعة: تُرسَل مرّة عند دخول نافذة الـ24 ساعة (وقبل آخر ساعة كي لا تتداخل مع طبقة الساعة)
            if ($consult->reminder_24h_sent_at === null && $startsAt->gt($now->copy()->addHour())) {
                if ($mail->send($consult->user, new ConsultReminderMail($consult, $remaining))) {
                    $consult->update(['reminder_24h_sent_at' => now()]);
                    $sent++;
                }

                continue; // لا نرسل طبقتين في نفس التشغيل
            }

            // طبقة الساعة: تُرسَل مرّة عند دخول نافذة الساعة (وقبل آخر 5 دقائق التي يغطّيها بريد الرابط)
            if ($consult->reminder_1h_sent_at === null
                && $startsAt->lte($now->copy()->addHour())
                && $startsAt->gt($now->copy()->addMinutes(5))) {
                if ($mail->send($consult->user, new ConsultReminderMail($consult, $remaining))) {
                    $consult->update(['reminder_1h_sent_at' => now()]);
                    $sent++;
                }
            }
        }

        $this->info('أُرسل '.$sent.' تذكير استشارة.');

        return self::SUCCESS;
    }

    /** وصف زمنيّ للوقت المتبقّي حتى الموعد (نحو X ساعة / نحو X دقيقة). */
    private function remainingLabel(CarbonInterface $now, CarbonInterface $startsAt): string
    {
        $mins = (int) ceil($now->diffInMinutes($startsAt));

        if ($mins >= 120) {
            return 'نحو '.(int) round($mins / 60).' ساعة';
        }

        if ($mins >= 60) {
            return 'نحو ساعة';
        }

        return 'نحو '.max(1, $mins).' دقيقة';
    }
}
