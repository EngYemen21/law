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
            if ($consult->user?->email) {
                Mail::to($consult->user->email)->queue(new MeetingLinkReady($consult));
            }
        }

        $this->info('أُطلق '.$due->count().' رابط جلسة.');

        return self::SUCCESS;
    }
}
