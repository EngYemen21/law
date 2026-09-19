<?php

namespace App\Listeners\Journey;

use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Events\Journey\AppointmentProposed;
use App\Models\User;
use App\Support\Audit;
use App\Support\Live;
use App\Support\Notify;

/** اقتراح موعدٍ من موظّف: تُنبَّه الإدارة لتعتمده، ولا يُخبَر العميل بشيء. */
final class HandleAppointmentProposed
{
    public function handle(AppointmentProposed $event): void
    {
        $consult = $event->consult;
        $appointment = $event->appointment;

        foreach (User::where('role', Role::Admin)->pluck('id') as $adminId) {
            Notify::send(
                $adminId,
                'cal',
                't-amber',
                "موعد استشارة ({$consult->ref}) اقترحه {$event->actorName}: {$appointment->day} · {$appointment->time} مع {$appointment->lawyer} — بانتظار اعتمادكم قبل إرساله للعميل."
            );
        }

        Audit::log(
            action: 'اقتراح موعد استشارة',
            description: "اقترح {$event->actorName} موعد الاستشارة {$consult->ref}: {$appointment->day} · {$appointment->time} مع {$appointment->lawyer} — بانتظار اعتماد الإدارة.",
            category: 'استشارات',
            auditable: $consult,
        );

        Live::push(new ConsultStatusBroadcast($consult));
    }
}
