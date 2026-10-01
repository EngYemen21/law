<?php

namespace App\Console\Commands;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Mail\MeetingReminderMail;
use App\Models\Meeting;
use App\Models\User;
use App\Services\MailService;
use App\Support\Notify;
use App\Support\ReminderLayer;
use App\Support\SessionWindow;
use App\Support\SettingsRegistry;
use Illuminate\Console\Command;

/**
 * **تذكير الاجتماعات القادمة بطبقتين** (قرار المالك 2026-10-01) — نظيرُ `consults:send-reminders`:
 *
 * 1. **البعيدة** (`meeting_reminder_lead`، 60د): بريدٌ للعميل والمحامي المسنَد **والمشاركين من الكادر** —
 *    كان المشارك يُشعَر عند الإنشاء ثمّ لا يُذكَّر. ختمها `reminder_sent_at`.
 * 2. **القريبة** (`meeting_reminder_near_minutes`، 30د): للعميل وحده — إشعارٌ في حسابه. ختمها `reminder_near_sent_at`.
 *    كانت معه رسالةٌ نصّيّة؛ صارت الرسالة واحدةً عند فتح الدخول فيها الموعد والرابط (قرار المالك 2026-10-01، «ب»).
 *
 * وما دون «فتح الدخول» يغطّيه إطلاق الرابط (`zoom:release-links`) ورسالته النصّيّة (`SessionLinkSms`). والأختام تُصفَّر بنقل الموعد
 * (`BookingMoved::markers`). بلا `starts_at` ⇒ لا تذكير.
 */
class SendMeetingReminders extends Command
{
    // بلا افتراضٍ في التوقيع: الافتراض من الإعدادات كي تضبطه الإدارة بلا نشر كود
    protected $signature = 'meetings:send-reminders {--lead= : فترة التذكير الأوّل قبل الموعد بالدقائق (الافتراض من الإعدادات)}';

    protected $description = 'تذكير الاجتماعات القادمة: بريدٌ أوّل للأطراف، ثمّ إشعارٌ للعميل (المدّتان من الإعدادات)';

    public function handle(MailService $mail): int
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
                $this->nudgeClient($meeting, $meeting->user, $remaining);
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

    /** الطبقة القريبة للعميل: إشعارٌ في حسابه — والرسالة النصّيّة واحدةٌ عند فتح الدخول (`SessionLinkSms`). */
    private function nudgeClient(Meeting $meeting, User $client, string $remaining): void
    {
        Notify::send($client->id, 'cal', 't-amber', "تذكير: اجتماعك «{$meeting->title}» بعد {$remaining} — يُفتح الدخول قبل الموعد بـ".SessionWindow::joinOpensLabel().'، ويصلك رابطه برسالةٍ نصّيّة حينها.');
    }
}
