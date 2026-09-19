<?php

namespace App\Listeners\Journey;

use App\Events\ConsultStatusBroadcast;
use App\Events\Journey\ConsultSessionStarted;
use App\Support\Live;
use App\Support\Notify;

final class HandleConsultSessionStarted
{
    public function handle(ConsultSessionStarted $event): void
    {
        $consult = $event->consult;

        Live::push(new ConsultStatusBroadcast($consult));

        $verb = match ($consult->channel) {
            'مرئية' => 'بدأت جلسة استشارتك المرئية — يمكنك الانضمام الآن من صفحة «استشاراتي»',
            'هاتفية' => 'بدأت مكالمة استشارتك الهاتفية',
            default => 'بدأت جلسة استشارتك الحضورية',
        };
        $icon = $consult->channel === 'مرئية' ? 'video' : ($consult->channel === 'هاتفية' ? 'phone' : 'office');

        Notify::send($consult->user_id, $icon, 't-blue', "{$verb} ({$consult->ref}).");
    }
}
