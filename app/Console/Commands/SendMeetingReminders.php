<?php

namespace App\Console\Commands;

use App\Mail\MeetingReminderMail;
use App\Models\Meeting;
use App\Services\MailService;
use Illuminate\Console\Command;

/**
 * تذكير بالاجتماعات القادمة قبل موعدها بفترة (افتراضياً 60د): يرسل MeetingReminderMail
 * للعميل والمحامي المسند، ويختم reminder_sent_at (idempotent — لا يذكّر مرتين).
 * يعمل فقط على اجتماعات لها starts_at (تعذّر التحليل ⇒ لا تذكير).
 */
class SendMeetingReminders extends Command
{
    protected $signature = 'meetings:send-reminders {--lead=60 : فترة التذكير قبل الموعد بالدقائق}';

    protected $description = 'إرسال تذكير بالاجتماعات القادمة المستحقّة عبر البريد';

    public function handle(): int
    {
        $lead = max(1, (int) $this->option('lead'));

        $due = Meeting::with(['user', 'assignedLawyer'])
            ->where('status', 'قادم')
            ->whereNull('reminder_sent_at')
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', now())
            ->where('starts_at', '<=', now()->addMinutes($lead))
            ->get();

        $sent = 0;

        foreach ($due as $meeting) {
            $when = $meeting->when_label ?: $meeting->starts_at?->format('Y-m-d H:i');
            $mins = (int) ceil(now()->diffInMinutes($meeting->starts_at));
            $remaining = $mins > 0 ? $mins.' دقيقة' : null;
            $ok = false;
            foreach ([$meeting->user, $meeting->assignedLawyer] as $recipient) {
                if ($recipient) {
                    $link = $meeting->portalUrlFor($recipient);
                    $ok = app(MailService::class)->send(
                        $recipient,
                        new MeetingReminderMail($recipient->name, $meeting->title, $when, $link, $remaining)
                    ) || $ok;
                }
            }

            // الختم بعد نجاح الإرسال فقط — الفشل العابر يُعاد في التشغيل التالي (بلا تذكير مزدوج بفضل withoutOverlapping)
            if ($ok) {
                $meeting->update(['reminder_sent_at' => now()]);
                $sent++;
            }
        }

        $this->info('أُرسل '.$sent.' تذكير اجتماع.');

        return self::SUCCESS;
    }
}
