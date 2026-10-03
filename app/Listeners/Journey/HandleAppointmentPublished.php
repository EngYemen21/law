<?php

namespace App\Listeners\Journey;

use App\Events\ConsultStatusBroadcast;
use App\Events\Journey\AppointmentPublished;
use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Support\Audit;
use App\Support\ConsultBookedSms;
use App\Support\ConsultBooking;
use App\Support\Live;
use App\Support\Notify;

/**
 * نشر الموعد بعد التزامه: بريد الحجز للعميل والمحامي، ورسالة تأكيدٍ نصّيّة للعميل، وإشعار العميل، وإشعار
 * الموظّف صاحب الاقتراح بما اعتُمد أو عُدّل، والبثّ اللحظيّ، وأثر التدقيق.
 */
final class HandleAppointmentPublished
{
    public function handle(AppointmentPublished $event): void
    {
        $consult = $event->consult;
        $label = $consult->channel ?: 'استشارة';

        ConsultBooking::sendBookingEmails($consult);
        ConsultBookedSms::send($consult);

        Notify::send(
            $consult->user_id,
            $consult->channel === 'مرئية' ? 'video' : ($consult->channel === 'هاتفية' ? 'phone' : 'office'),
            't-green',
            "تم تحديد موعد استشارتك ({$label}) {$consult->day} الساعة {$consult->time} — رقم الاستشارة {$consult->ref}."
        );

        if ($consult->assigned_lawyer_id) {
            Notify::send($consult->assigned_lawyer_id, 'cal', 't-blue', "موعد استشارة جديد ({$consult->ref}): {$consult->day} · {$consult->time}.");
        }

        if ($event->proposerId !== null && $event->proposerId !== $event->actorId) {
            Notify::send(
                $event->proposerId,
                'check',
                't-green',
                $event->changes === []
                    ? "اعتمدت الإدارة موعد الاستشارة ({$consult->ref}) كما اقترحته، وأُرسل للعميل."
                    : "اعتمدت الإدارة موعد الاستشارة ({$consult->ref}) بعد تعديل — ".implode('، ', $event->changes).' — وأُرسل للعميل.'
            );
        }

        Audit::log(
            action: 'اعتماد ونشر موعد استشارة',
            description: "نُشر موعد الاستشارة {$consult->ref} للعميل: {$consult->day} · {$consult->time} مع {$consult->lawyer}"
                .($event->changes === [] ? '.' : ' — بعد تعديل الإدارة: '.implode('، ', $event->changes).'.'),
            category: 'استشارات',
            auditable: $consult,
            afterState: ['الموعد' => $consult->day.' · '.$consult->time, 'المحامي' => $consult->lawyer],
        );

        Live::push(new ConsultStatusBroadcast($consult));
        if ($event->card !== null) {
            Live::push(new TicketMessageBroadcast($event->card));
        }
        if ($event->ticketMoved && $consult->ticket !== null) {
            Live::push(new TicketStatusBroadcast($consult->ticket));
        }
    }
}
