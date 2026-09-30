<?php

namespace App\Console\Commands;

use App\Domain\Journey\Enums\SessionState;
use App\Events\ConsultStatusBroadcast;
use App\Mail\MeetingLinkReady;
use App\Models\Consult;
use App\Models\User;
use App\Support\Live;
use App\Support\SessionWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * يُطلق رابط الجلسة المرئية قبل الموعد بـ`session_join_opens_minutes` (افتراضها 5 دقائق): يضبط link_released_at، يبثّ (يفعّل زر الدخول
 * لحظياً للعميل والمحامي)، ويرسل بريد الرابط. idempotent — لا يُطلق مرتين.
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
            // والجارية تُطلَق أيضاً: الطاقم يبدأ قبل الموعد بربع ساعة، وبريد الرابط للعميل يلزم
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

        $this->info('أُطلق '.$due->count().' رابط جلسة.');

        return self::SUCCESS;
    }
}
