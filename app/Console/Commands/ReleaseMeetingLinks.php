<?php

namespace App\Console\Commands;

use App\Events\ConsultStatusBroadcast;
use App\Mail\MeetingLinkReady;
use App\Models\Consult;
use App\Support\Live;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * يُطلق رابط الجلسة المرئية قبل الموعد بـ5 دقائق: يضبط link_released_at، يبثّ (يفعّل زر الدخول
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
            ->where('session', '!=', 'منتهية')
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now()->addMinutes(5))
            ->where('starts_at', '>=', now()->subMinutes(60))
            ->get();

        foreach ($due as $consult) {
            $consult->update(['link_released_at' => now()]);
            Live::push(new ConsultStatusBroadcast($consult)); // canJoin=true → الزر يُفعّل لحظياً

            // 1. بريد العميل
            if ($consult->user?->email) {
                Mail::to($consult->user->email)->send(new MeetingLinkReady($consult, forLawyer: false));
            }

            // 2. بريد المحامي المسند
            $lawyerUser = $consult->assignedLawyer ?? ($consult->assigned_lawyer_id ? \App\Models\User::find($consult->assigned_lawyer_id) : null);
            if ($lawyerUser?->email && $lawyerUser->id !== $consult->user_id) {
                Mail::to($lawyerUser->email)->send(new MeetingLinkReady($consult, forLawyer: true));
            }
        }

        $this->info('أُطلق '.$due->count().' رابط جلسة.');

        return self::SUCCESS;
    }
}
