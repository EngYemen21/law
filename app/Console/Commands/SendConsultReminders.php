<?php

namespace App\Console\Commands;

use App\Jobs\SendSmsJob;
use App\Mail\ConsultReminderMail;
use App\Models\Consult;
use App\Services\MailService;
use App\Services\TaqnyatSmsService;
use App\Support\Phone;
use App\Support\ReminderLayer;
use Illuminate\Console\Command;

/**
 * تذكير بمواعيد الاستشارات المدفوعة القادمة — طبقتان مستقلّتان لكلٍّ ختمها وقناتها:
 *   • نحو 24 ساعة قبل الموعد — بريد إلكتروني (reminder_24h_sent_at)
 *   • نحو 30 دقيقة قبل الموعد — رسالة نصّية SMS (reminder_30m_sent_at)
 *
 * كانت الطبقة القريبة «قبل ساعة» بالبريد؛ صارت 30 دقيقة برسالة نصّية لأن البريد قد لا
 * يُفتح قبيل الموعد. ونافذة الساعة كانت تبتلع الثلاثين دقيقة، فإضافة طبقة ثالثة كانت
 * ستُنتج تذكيرين متقاربين — لذا استُبدلت لا أُضيفت.
 *
 * آخر 5 دقائق ليست من مسؤولية هذا الأمر: يغطّيها zoom:release-links بإطلاق رابط الجلسة.
 * ويعمل فقط على استشارات **مدفوعة** لها starts_at (الرسالة تكلّف مالاً).
 */
class SendConsultReminders extends Command
{
    protected $signature = 'consults:send-reminders';

    protected $description = 'إرسال تذكيرات مواعيد الاستشارات (بريد قبل 24 ساعة · SMS قبل 30 دقيقة)';

    public function handle(MailService $mail, TaqnyatSmsService $sms): int
    {
        $now = now();

        $far = new ReminderLayer('reminder_24h_sent_at', upperMinutes: 1440, lowerMinutes: 30);
        $near = new ReminderLayer('reminder_30m_sent_at', upperMinutes: 30, lowerMinutes: 5);

        // القادمة المحجوزة والمسدَّدة خلال الأفق الأقصى (24 ساعة).
        // paid_at صريح: الرسالة النصّية تكلّف مالاً فلا تُنفَق على طلب غير مسدَّد.
        $consults = Consult::with('user')
            ->where('session', 'بانتظار الجلسة')
            ->whereNotNull('paid_at')
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->copy()->addDay())
            ->get();

        $mails = 0;
        $texts = 0;

        foreach ($consults as $consult) {
            $startsAt = $consult->starts_at;
            $remaining = ReminderLayer::remainingLabel($now, $startsAt);

            // الطبقة البعيدة — بريد
            if ($far->isDue($now, $startsAt, $consult->reminder_24h_sent_at)) {
                if ($consult->user?->email && $mail->send($consult->user, new ConsultReminderMail($consult, $remaining))) {
                    $consult->update([$far->stampColumn => now()]);
                    $mails++;
                }

                continue; // لا طبقتين في تشغيل واحد
            }

            // الطبقة القريبة — رسالة نصّية
            if ($near->isDue($now, $startsAt, $consult->reminder_30m_sent_at)) {
                if ($this->textReminder($sms, $consult, $remaining)) {
                    $consult->update([$near->stampColumn => now()]);
                    $texts++;
                }
            }
        }

        $this->info("أُرسل {$mails} تذكير بريد و{$texts} رسالة نصّية.");

        return self::SUCCESS;
    }

    /**
     * يجدول الرسالة النصّية. يعيد false بلا ختم حين يتعذّر الإرسال (لا جوال أو مزوّد غير
     * مهيّأ) كي يُعاد في التشغيل التالي بدل أن يُفقد التذكير صامتاً.
     */
    private function textReminder(TaqnyatSmsService $sms, Consult $consult, string $remaining): bool
    {
        $phone = (string) ($consult->user?->phone ?? '');

        if ($phone === '' || ! Phone::isSendable($phone) || ! $sms->isConfigured()) {
            return false;
        }

        SendSmsJob::dispatch(Phone::intl($phone), $this->smsBody($consult, $remaining));

        return true;
    }

    /** نصّ الرسالة — قصير عمداً: الرسائل تُحاسَب بعدد المقاطع. */
    private function smsBody(Consult $consult, string $remaining): string
    {
        $place = $consult->channel === 'حضورية' ? $consult->placeLabel() : $consult->channel;

        return "تذكير: موعد استشارتك {$consult->ref} بعد {$remaining} ({$place}). "
            .config('app.name');
    }
}
