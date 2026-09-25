<?php

namespace App\Listeners\Journey;

use App\Events\ConsultStatusBroadcast;
use App\Events\Journey\ConsultRescheduled;
use App\Events\TicketStatusBroadcast;
use App\Jobs\DropZoomMeetingJob;
use App\Mail\ConsultRescheduledMail;
use App\Services\MailService;
use App\Support\Audit;
use App\Support\Booking\BookingStaff;
use App\Support\Live;
use App\Support\Notify;

/**
 * آثار إعادة الجدولة بعد الالتزام — **لكلّ طرفٍ ما يخصّه**:
 *
 * - **Zoom:** حذف الاجتماع القديم في الخلفيّة (`DropZoomMeetingJob`) — لا يقف زرّ المستخدم عليه.
 * - **العميل:** إشعارٌ وبريد، بنصٍّ صادق: المكتب يحدّد الموعد (لا «اختر موعدك»).
 * - **طاقم الحجز:** تنبيهٌ أنّ عليه حجز موعدٍ جديد — كان لا يُنبَّه أحد.
 * - **المحامي المسنَد:** يُبلَّغ إن أعاد غيرُه جدولة استشارته.
 */
final class HandleConsultRescheduled
{
    public function handle(ConsultRescheduled $event): void
    {
        $consult = $event->consult;
        $consult->loadMissing('user');
        $actorName = $event->actor->name ?? 'النظام';

        if ($event->oldMeetId !== null) {
            DropZoomMeetingJob::dispatch($event->oldMeetId, (string) $consult->ref);
        }

        if ($event->ticketReverted && $consult->ticket !== null) {
            Live::push(new TicketStatusBroadcast($consult->ticket));
        }

        Audit::log(
            action: 'إعادة جدولة استشارة',
            description: "أعاد {$actorName} جدولة الاستشارة {$consult->ref} ({$event->reason}) — كان موعدها: {$event->oldWhen}.",
            category: 'استشارات',
            severity: 'warning',
            auditable: $consult,
            beforeState: ['الموعد' => $event->oldWhen],
            afterState: ['الحالة' => 'بانتظار تحديد الموعد', 'السبب' => $event->reason, 'المرّة' => (int) $consult->reschedule_count],
        );

        Live::push(new ConsultStatusBroadcast($consult));

        Notify::send($consult->user_id, 'cal', 't-amber', "أُلغي موعد استشارتك ({$consult->ref}) — سيحدّد المكتب موعداً جديداً مع المستشار المختص ويصلك إشعارٌ به. لا تحضر في الموعد السابق.");
        if ($consult->user?->email) {
            app(MailService::class)->send($consult->user, new ConsultRescheduledMail($consult, $event->oldWhen, $event->reason));
        }

        BookingStaff::notify(
            'cal', 't-amber',
            "أُعيدت جدولة الاستشارة ({$consult->ref}) — {$event->reason}. احجز موعداً جديداً ليُعتمد ويُرسل للعميل.",
            $event->actor?->id,
        );

        $lawyerId = $consult->assigned_lawyer_id;
        if ($lawyerId !== null && $lawyerId !== $event->actor?->id) {
            Notify::send($lawyerId, 'cal', 't-amber', "أعاد {$actorName} جدولة استشارتك ({$consult->ref}) — {$event->reason}. يُبلَّغ الموعد الجديد حين يُعتمد.");
        }
    }
}
