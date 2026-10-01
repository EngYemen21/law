<?php

namespace App\Console\Commands;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Events\ConsultStatusBroadcast;
use App\Events\MeetingStatusBroadcast;
use App\Mail\MeetingLinkReady;
use App\Mail\MeetingReminderMail;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Services\MailService;
use App\Support\Live;
use App\Support\Notify;
use App\Support\SessionWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * يُطلق رابط الجلسة المرئية قبل الموعد بـ`session_join_opens_minutes` (افتراضها 5 دقائق): يضبط link_released_at، يبثّ (يفعّل زر الدخول
 * لحظياً للعميل والمحامي)، ويرسل بريد الرابط. idempotent — لا يُطلق مرتين.
 *
 * **والاجتماع كالاستشارة** (قرار المالك 2026-10-01): كان الإعداد يعلن «يُرسل رابط الجلسة للعميل — للاستشارة
 * والاجتماع» والأمر يمرّ بالاستشارات وحدها. الآن يُطلَق للاجتماع أيضاً (`releaseMeetings`).
 */
class ReleaseMeetingLinks extends Command
{
    protected $signature = 'zoom:release-links';

    protected $description = 'إطلاق روابط الجلسات المرئية المستحقّة (قبل 5د) وتفعيل الدخول';

    public function handle(): int
    {
        $due = Consult::with('user')
            ->where('channel', 'مرئية')
            ->whereNull('link_released_at')
            // والجارية تُطلَق أيضاً: الطاقم يبدأ قبل الموعد بـ`consult_staff_start_minutes`، وبريد الرابط للعميل يلزم
            ->whereIn('session', [SessionState::Waiting->value, SessionState::Live->value])
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now()->addMinutes(SessionWindow::joinOpensBeforeMinutes()))
            // لا يُطلق رابطُ جلسةٍ فاتت دون أن تبدأ — الحدّ مهلة الفوات من البداية (`SessionWindow`)،
            // وكان ٦٠ منقوشة تطابق «المدّة» صدفةً.
            ->where('starts_at', '>=', now()->subMinutes(SessionWindow::missedAfterMinutes()))
            ->get();

        foreach ($due as $consult) {
            $consult->update(['link_released_at' => now()]);
            Live::push(new ConsultStatusBroadcast($consult)); // canJoin=true → الزر يُفعّل لحظياً

            // 1. بريد العميل
            if ($consult->user?->email) {
                Mail::to($consult->user->email)->send(new MeetingLinkReady($consult, forLawyer: false));
            }

            // 2. بريد المحامي المسند
            $lawyerUser = $consult->assignedLawyer ?? ($consult->assigned_lawyer_id ? User::find($consult->assigned_lawyer_id) : null);
            if ($lawyerUser?->email && $lawyerUser->id !== $consult->user_id) {
                Mail::to($lawyerUser->email)->send(new MeetingLinkReady($consult, forLawyer: true));
            }
        }

        $meetings = $this->releaseMeetings();

        $this->info('أُطلق '.$due->count().' رابط جلسة و'.$meetings.' رابط اجتماع.');

        return self::SUCCESS;
    }

    /**
     * روابط الاجتماعات المستحقّة: نافذة الدخول نفسها (`session_join_opens_minutes` قبل الموعد، وحتى مهلة
     * الفوات بعده). إشعارٌ في الحساب وبريدٌ بزرّ غرفة المنصّة للعميل، وبريدٌ للمحامي المسنَد، وبثٌّ يفعّل
     * زرّ الدخول لحظيّاً. ختم `link_released_at` يمنع التكرار، ونقل الموعد يُصفّره (`BookingMoved::markers`).
     */
    private function releaseMeetings(): int
    {
        $due = Meeting::with(['user', 'assignedLawyer'])
            ->whereIn('status', [MeetingStatus::Upcoming->value, MeetingStatus::Live->value])
            ->whereNull('link_released_at')
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now()->addMinutes(SessionWindow::joinOpensBeforeMinutes()))
            ->where('starts_at', '>=', now()->subMinutes(SessionWindow::missedAfterMinutes()))
            ->get();

        $mail = app(MailService::class);
        foreach ($due as $meeting) {
            $meeting->update(['link_released_at' => now()]);
            Live::push(new MeetingStatusBroadcast($meeting));

            $when = $meeting->when_label ?: $meeting->starts_at?->format('Y-m-d H:i');
            if ($meeting->user) {
                Notify::send($meeting->user->id, 'video', 't-cyan', "فُتح باب الدخول لاجتماع «{$meeting->title}» — ادخل الغرفة من قسم الاجتماعات بالمنصّة.");
                $mail->send($meeting->user, new MeetingReminderMail($meeting->user->name, $meeting->title, $when, $meeting->joinLink($meeting->user), SessionWindow::joinOpensLabel()));
            }
            if ($meeting->assignedLawyer) {
                $mail->send($meeting->assignedLawyer, new MeetingReminderMail($meeting->assignedLawyer->name, $meeting->title, $when, $meeting->joinLink($meeting->assignedLawyer), SessionWindow::joinOpensLabel()));
            }
        }

        return $due->count();
    }
}
