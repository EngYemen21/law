<?php

namespace App\Listeners\Journey;

use App\Events\Journey\SessionEndedInSystem;
use App\Jobs\EndZoomMeetingJob;

/**
 * **أُنهيت الجلسة في النظام ⇒ تُغلق غرفة Zoom** (قرار المالك 2026-09-26) — في الطابور لا في
 * طلب الزرّ، وبعد التزام الختم لا داخله.
 */
final class HandleSessionEndedInSystem
{
    public function handle(SessionEndedInSystem $event): void
    {
        EndZoomMeetingJob::dispatch($event->meetId, $event->ref);
    }
}
