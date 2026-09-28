<?php

namespace App\Console\Commands;

use App\Domain\Journey\Enums\HearingStatus;
use App\Mail\HearingReminderMail;
use App\Models\CaseHearing;
use App\Services\MailService;
use App\Support\Notify;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * تذكير بجلسات القضايا القادمة — طبقتان مستقلّتان (نحو 24 ساعة، ونحو ساعة)، كلٌّ idempotent بختمه الخاصّ.
 * يصل التذكير كإشعار داخلي (Notify) + بريد، للعميل والمحامي المسند. يعمل فقط على جلسات لها starts_at.
 */
class SendHearingReminders extends Command
{
    protected $signature = 'hearings:send-reminders';

    protected $description = 'إرسال تذكيرات جلسات القضايا (قبل 24 ساعة وقبل ساعة) — إشعار داخلي + بريد';

    public function handle(MailService $mail): int
    {
        $now = now();

        // الجلسات المجدولة القادمة (لها موعد حقيقي) خلال أفق 24 ساعة
        $hearings = CaseHearing::with(['legalCase.user', 'legalCase.assignedLawyer'])
            ->where('status', HearingStatus::Scheduled->value)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->copy()->addDay())
            ->get();

        $sent = 0;

        foreach ($hearings as $hearing) {
            $case = $hearing->legalCase;
            if ($case === null) {
                continue;
            }
            $startsAt = $hearing->starts_at;
            $remaining = $this->remainingLabel($now, $startsAt);

            // طبقة 24 ساعة (وقبل آخر ساعة كي لا تتداخل مع طبقة الساعة)
            if ($hearing->reminder_24h_sent_at === null && $startsAt->gt($now->copy()->addHour())) {
                $this->dispatchReminder($mail, $hearing, $remaining);
                $hearing->update(['reminder_24h_sent_at' => now()]);
                $sent++;

                continue; // لا نرسل طبقتين في نفس التشغيل
            }

            // طبقة الساعة (وقبل آخر 5 دقائق)
            if ($hearing->reminder_1h_sent_at === null
                && $startsAt->lte($now->copy()->addHour())
                && $startsAt->gt($now->copy()->addMinutes(5))) {
                $this->dispatchReminder($mail, $hearing, $remaining);
                $hearing->update(['reminder_1h_sent_at' => now()]);
                $sent++;
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

    /** وصف زمنيّ للوقت المتبقّي حتى الجلسة (نحو X ساعة / نحو X دقيقة). */
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
