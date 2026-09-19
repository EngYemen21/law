<?php

namespace App\Listeners\Journey;

use App\Events\ConsultStatusBroadcast;
use App\Events\Journey\ConsultMarkedNoShow;
use App\Support\Live;
use App\Support\Notify;

final class HandleConsultMarkedNoShow
{
    public function handle(ConsultMarkedNoShow $event): void
    {
        $consult = $event->consult;

        Live::push(new ConsultStatusBroadcast($consult));
        Notify::send($consult->user_id, 'clock', 't-red', "لم تُعقد جلسة استشارتك ({$consult->ref}) في موعدها. يمكنك التواصل مع المكتب لإعادة الجدولة.");
    }
}
