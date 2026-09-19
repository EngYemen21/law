<?php

namespace App\Listeners\Journey;

use App\Events\ConsultStatusBroadcast;
use App\Events\Journey\ConsultRescheduled;
use App\Events\TicketStatusBroadcast;
use App\Services\GoogleCalendarService;
use App\Services\ZoomService;
use App\Support\Audit;
use App\Support\Live;
use App\Support\Notify;

/** آثار إعادة الجدولة: حذف اجتماع Zoom القديم وحدث Google، والتدقيق، والبثّ، وإشعار العميل. */
final class HandleConsultRescheduled
{
    public function handle(ConsultRescheduled $event): void
    {
        $consult = $event->consult;

        if ($event->oldMeetId !== null) {
            app(ZoomService::class)->deleteMeeting($event->oldMeetId);
        }
        if ($event->oldGoogleEventId !== null) {
            GoogleCalendarService::deleteEvent($event->oldGoogleEventId);
        }

        if ($event->ticketReverted && $consult->ticket !== null) {
            Live::push(new TicketStatusBroadcast($consult->ticket));
        }

        Audit::log(
            action: 'إعادة جدولة استشارة',
            description: "أعاد {$event->actorName} الاستشارة {$consult->ref} لاختيار موعد جديد (كان موعدها: {$event->oldWhen}).",
            category: 'استشارات',
            severity: 'warning',
            auditable: $consult,
            beforeState: ['الموعد' => $event->oldWhen],
            afterState: ['الحالة' => 'بانتظار تحديد الموعد'],
        );

        Live::push(new ConsultStatusBroadcast($consult));
        Notify::send($consult->user_id, 'cal', 't-amber', "أُعيدت جدولة استشارتك ({$consult->ref}) — سوف يتم تحديد موعد جديد مع المستشار المختص ويصلك إشعار به.");
    }
}
