<?php

namespace App\Console\Commands;

use App\Domain\Journey\Enums\HearingStatus;
use App\Mail\HearingReminderMail;
use App\Models\CaseHearing;
use App\Services\MailService;
use App\Support\Notify;
use App\Support\ReminderLayer;
use App\Support\SettingsRegistry;
use Illuminate\Console\Command;

/**
 * تذكير بجلسات القضايا القادمة — طبقتان مستقلّتان (افتراضهما 24 ساعة وساعة، من الإعدادات)، كلٌّ idempotent بختمه.
 * يصل التذكير كإشعار داخلي (Notify) + بريد، للعميل والمحامي المسند. يعمل فقط على جلسات لها starts_at.
 *
 * عمودا الختم باسميهما التاريخيّين (`reminder_24h_sent_at` للأولى و`reminder_1h_sent_at` للثانية) — يختمان الطبقة
 * لا مدّتها، فتغيير المدّة من الإعدادات لا يمسّهما.
 */
class SendHearingReminders extends Command
{
    /** آخر دقائق قبل الجلسة لا يُرسل فيها تذكير — لا يصل في وقتٍ ينفع. */
    private const LAST_CALL_MINUTES = 5;

    protected $signature = 'hearings:send-reminders';

    protected $description = 'إرسال تذكيرات جلسات القضايا (طبقتان من الإعدادات) — إشعار داخلي + بريد';

    public function handle(MailService $mail): int
    {
        $now = now();

        $nearMinutes = SettingsRegistry::int('hearing_reminder_near_minutes');
        $far = new ReminderLayer('reminder_24h_sent_at', upperMinutes: SettingsRegistry::int('hearing_reminder_far_minutes'), lowerMinutes: $nearMinutes);
        $near = new ReminderLayer('reminder_1h_sent_at', upperMinutes: $nearMinutes, lowerMinutes: self::LAST_CALL_MINUTES);

        // الجلسات المجدولة القادمة (لها موعد حقيقي) خلال أفق الطبقة الأولى
        $hearings = CaseHearing::with(['legalCase.user', 'legalCase.assignedLawyer'])
            ->where('status', HearingStatus::Scheduled->value)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->copy()->addMinutes((int) $far->upperMinutes))
            ->get();

        $sent = 0;

        foreach ($hearings as $hearing) {
            $case = $hearing->legalCase;
            if ($case === null) {
                continue;
            }
            $startsAt = $hearing->starts_at;
            $remaining = ReminderLayer::remainingLabel($now, $startsAt);

            foreach ([$far, $near] as $layer) {
                if ($layer->isDue($now, $startsAt, $hearing->{$layer->stampColumn})) {
                    $this->dispatchReminder($mail, $hearing, $remaining);
                    $hearing->update([$layer->stampColumn => now()]);
                    $sent++;

                    break; // لا نرسل طبقتين في نفس التشغيل
                }
            }
        }

        $this->info('أُرسل '.$sent.' تذكير جلسة.');

        return self::SUCCESS;
    }

    /** يرسل إشعارًا داخليًّا (مضمون) + بريدًا (best-effort) للعميل والمحامي المسند. */
    private function dispatchReminder(MailService $mail, CaseHearing $hearing, string $remaining): void
    {
        $case = $hearing->legalCase;
        $body = "تذكير: جلسة قضيتك {$case->number} «{$hearing->title}» بعد {$remaining}.";

        // إشعار داخلي مضمون
        Notify::send($case->user_id, 'cal', 't-cyan', $body);
        if ($case->assigned_lawyer_id) {
            Notify::send($case->assigned_lawyer_id, 'cal', 't-cyan', $body);
        }

        // بريد best-effort لمن له بريد
        if ($case->user) {
            $mail->send($case->user, new HearingReminderMail($hearing, $remaining));
        }
        if ($case->assignedLawyer) {
            $mail->send($case->assignedLawyer, new HearingReminderMail($hearing, $remaining));
        }
    }
}
