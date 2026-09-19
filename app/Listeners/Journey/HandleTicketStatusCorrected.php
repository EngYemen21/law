<?php

namespace App\Listeners\Journey;

use App\Events\Journey\TicketStatusCorrected;
use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Support\Audit;
use App\Support\Live;

/** تصحيحٌ إداريّ للحالة: ملاحظةٌ داخليّة بالسبب، وأثرُ تدقيقٍ تحذيريّ، وبثٌّ لحظيّ. */
final class HandleTicketStatusCorrected
{
    public function handle(TicketStatusCorrected $event): void
    {
        $ticket = $event->ticket;

        $note = $ticket->messages()->create([
            'who' => 'note',
            'name' => $event->actorName,
            'role' => 'تصحيح حالة',
            'body' => '<p>صُحّحت الحالة من «'.e($event->from).'» إلى «'.e($event->to).'» — السبب: '.e($event->reason).'</p>',
            'time_label' => now()->format('h:i').' '.(now()->hour < 12 ? 'ص' : 'م'),
        ]);

        Audit::log(
            action: 'تصحيح حالة تذكرة',
            description: "صحّح {$event->actorName} حالة التذكرة {$ticket->number} من «{$event->from}» إلى «{$event->to}» — السبب: {$event->reason}",
            category: 'تذاكر',
            severity: 'warning',
            auditable: $ticket,
            auditableRef: $ticket->number,
            beforeState: ['الحالة' => $event->from],
            afterState: ['الحالة' => $event->to],
        );

        Live::push(new TicketMessageBroadcast($note));
        Live::push(new TicketStatusBroadcast($ticket));
    }
}
