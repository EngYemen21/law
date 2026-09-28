<?php

namespace App\Console\Commands;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Mail\MeetingReminderMail;
use App\Models\Meeting;
use App\Services\MailService;
use App\Support\SettingsRegistry;
use Illuminate\Console\Command;

/**
 * تذكير بالاجتماعات القادمة قبل موعدها بفترة (افتراضياً 60د): يرسل MeetingReminderMail
 * للعميل والمحامي المسند، ويختم reminder_sent_at (idempotent — لا يذكّر مرتين).
 * يعمل فقط على اجتماعات لها starts_at (تعذّر التحليل ⇒ لا تذكير).
 */
class SendMeetingReminders extends Command
{
    // بلا افتراضٍ في التوقيع: الافتراض من الإعدادات كي تضبطه الإدارة بلا نشر كود
    protected $signature = 'meetings:send-reminders {--lead= : فترة التذكير قبل الموعد بالدقائق (الافتراض من الإعدادات)}';

    protected $description = 'إرسال تذكير بالاجتماعات القادمة المستحقّة عبر البريد';

    public function handle(): int
    {
        $option = $this->option('lead');
        $lead = max(1, $option === null || $option === '' ? SettingsRegistry::int('meeting_reminder_lead') : (int) $option);

        $due = Meeting::with(['user', 'assignedLawyer'])
            ->where('status', MeetingStatus::Upcoming->value)
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
