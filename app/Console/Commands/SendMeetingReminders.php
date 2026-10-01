<?php

namespace App\Console\Commands;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Jobs\SendSmsJob;
use App\Mail\MeetingReminderMail;
use App\Models\Meeting;
use App\Models\User;
use App\Services\MailService;
use App\Services\TaqnyatSmsService;
use App\Support\Notify;
use App\Support\Phone;
use App\Support\ReminderLayer;
use App\Support\SessionWindow;
use App\Support\SettingsRegistry;
use Illuminate\Console\Command;

/**
 * **تذكير الاجتماعات القادمة بطبقتين** (قرار المالك 2026-10-01) — نظيرُ `consults:send-reminders`:
 *
 * 1. **البعيدة** (`meeting_reminder_lead`، 60د): بريدٌ للعميل والمحامي المسنَد **والمشاركين من الكادر** —
 *    كان المشارك يُشعَر عند الإنشاء ثمّ لا يُذكَّر. ختمها `reminder_sent_at`.
 * 2. **القريبة** (`meeting_reminder_near_minutes`، 30د): للعميل وحده — إشعارٌ في حسابه، ورسالةٌ نصّيّة
 *    إلى جواله متى كان المزوّد مهيّأً والرقم صالحاً. ختمها `reminder_near_sent_at`.
 *
 * وما دون «فتح الدخول» يغطّيه إطلاق الرابط (`zoom:release-links`). والأختام تُصفَّر بنقل الموعد
 * (`BookingMoved::markers`). بلا `starts_at` ⇒ لا تذكير.
 */
class SendMeetingReminders extends Command
{
    // بلا افتراضٍ في التوقيع: الافتراض من الإعدادات كي تضبطه الإدارة بلا نشر كود
    protected $signature = 'meetings:send-reminders {--lead= : فترة التذكير الأوّل قبل الموعد بالدقائق (الافتراض من الإعدادات)}';

    protected $description = 'تذكير الاجتماعات القادمة: بريدٌ أوّل للأطراف، ثمّ إشعارٌ ورسالة نصّيّة للعميل (المدّتان من الإعدادات)';

    public function handle(MailService $mail, TaqnyatSmsService $sms): int
    {
        $now = now();
        $option = $this->option('lead');
        $lead = max(1, $option === null || $option === '' ? SettingsRegistry::int('meeting_reminder_lead') : (int) $option);
        $nearMinutes = SettingsRegistry::int('meeting_reminder_near_minutes');

        $far = new ReminderLayer('reminder_sent_at', upperMinutes: $lead, lowerMinutes: min($nearMinutes, $lead - 1));
        $near = new ReminderLayer('reminder_near_sent_at', upperMinutes: $nearMinutes, lowerMinutes: SessionWindow::joinOpensBeforeMinutes());

        $due = Meeting::with(['user', 'assignedLawyer', 'participantUsers'])
            ->where('status', MeetingStatus::Upcoming->value)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->copy()->addMinutes(max($lead, $nearMinutes)))
            ->get();

        $mails = 0;
        $nudges = 0;

        foreach ($due as $meeting) {
            $startsAt = $meeting->starts_at;
            $remaining = ReminderLayer::remainingLabel($now, $startsAt);

            if ($far->isDue($now, $startsAt, $meeting->reminder_sent_at)) {
                // الختم بعد نجاح إرسالٍ واحد على الأقلّ — الفشل العابر يُعاد في التشغيل التالي
                if ($this->mailParties($mail, $meeting, $remaining)) {
                    $meeting->update(['reminder_sent_at' => now()]);
                    $mails++;
                }

                continue; // لا طبقتين في تشغيلٍ واحد
            }

            if ($meeting->user && $near->isDue($now, $startsAt, $meeting->reminder_near_sent_at)) {
                $this->nudgeClient($sms, $meeting, $meeting->user, $remaining);
                $meeting->update(['reminder_near_sent_at' => now()]);
                $nudges++;
            }
        }

        $this->info("أُرسل {$mails} تذكير بريد و{$nudges} تذكير قريب للعميل.");

        return self::SUCCESS;
    }

    /** بريد الطبقة البعيدة لأطراف الاجتماع كلّهم — كلُّ حسابٍ مرّةً وإن تكرّر دوره. */
    private function mailParties(MailService $mail, Meeting $meeting, string $remaining): bool
    {
        $when = $meeting->when_label ?: $meeting->starts_at?->format('Y-m-d H:i');
        $recipients = collect([$meeting->user, $meeting->assignedLawyer, ...$meeting->participantUsers])
            ->filter()
            ->unique('id');

        $ok = false;
        foreach ($recipients as $recipient) {
            $ok = $mail->send(
                $recipient,
                new MeetingReminderMail($recipient->name, $meeting->title, $when, $meeting->portalUrlFor($recipient), $remaining)
            ) || $ok;
        }

        return $ok;
    }

    /**
     * الطبقة القريبة للعميل: إشعارٌ في حسابه دائماً، ورسالةٌ نصّيّة حين يصحّ إرسالها. والرسالة لا تُرسل
     * إلّا إلى جوال العميل المسجَّل وبرقمٍ صالح (`Phone::isSendable`) — لا رقمٌ من خارج حسابه.
     */
    private function nudgeClient(TaqnyatSmsService $sms, Meeting $meeting, User $client, string $remaining): void
    {
        Notify::send($client->id, 'cal', 't-amber', "تذكير: اجتماعك «{$meeting->title}» بعد {$remaining} — تدخل غرفته من قسم الاجتماعات بالمنصّة.");

        $phone = (string) ($client->phone ?? '');
        if ($phone !== '' && Phone::isSendable($phone) && $sms->isConfigured()) {
            // قصيرٌ عمداً: الرسائل تُحاسَب بعدد المقاطع. والتوقيع اسم المكتب من الإعدادات.
            SendSmsJob::dispatch(Phone::intl($phone), "تذكير: اجتماعك {$meeting->ref} بعد {$remaining}. ".SettingsRegistry::str('office_name'));
        }
    }
}
