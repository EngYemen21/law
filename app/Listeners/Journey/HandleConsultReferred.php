<?php

namespace App\Listeners\Journey;

use App\Events\ConsultStatusBroadcast;
use App\Events\Journey\ConsultReferred;
use App\Support\Live;
use App\Support\Notify;

final class HandleConsultReferred
{
    public function handle(ConsultReferred $event): void
    {
        $consult = $event->consult;

        Live::push(new ConsultStatusBroadcast($consult));

        // النصّ يتبع وجود الموعد — «ستُعقد الجلسة في موعدها» لا تُقال لموعدٍ لم يُحجز
        Notify::send($consult->user_id, 'scale', 't-green', $consult->starts_at !== null
            ? "أُحيلت استشارتك ({$consult->ref}) إلى المستشار المختص وستُعقد الجلسة في موعدها المحدَّد."
            : "أُحيلت استشارتك ({$consult->ref}) إلى المستشار المختص، وسنوافيك بموعد الجلسة.");
    }
}
