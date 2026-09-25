<?php

namespace App\Listeners\Journey;

use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Events\Journey\ConsultPaid;
use App\Events\TicketStatusBroadcast;
use App\Mail\ConsultPaidMail;
use App\Models\User;
use App\Services\MailService;
use App\Support\Booking\BookingStaff;
use App\Support\Live;
use App\Support\Notify;

/** آثار سداد الاستشارة: إشعار العميل وبريده، وإشعار الإدارة، والبثّ. */
final class HandleConsultPaid
{
    public function handle(ConsultPaid $event): void
    {
        $consult = $event->consult;
        $consult->loadMissing('user');

        $icon = $consult->channel === 'مرئية' ? 'video' : ($consult->channel === 'هاتفية' ? 'phone' : 'office');
        Notify::send($consult->user_id, $icon, 't-green', "تمّت عملية الدفع بنجاح — استشارتك ({$consult->ref}). سوف يتم تحديد موعد جلسة استشارية مع المستشار المختص وتزويدك بالموعد المحدد.");

        if ($consult->user?->email) {
            app(MailService::class)->send($consult->user, new ConsultPaidMail($consult));
        }

        foreach (User::where('role', Role::Admin)->get() as $admin) {
            Notify::send($admin->id, 'card', 't-green', "تمّت عملية دفع استشارة {$consult->ref} بمبلغ {$consult->total} ر.س من العميل.");
        }

        // **الحجز بيد الطاقم** — والموظّفون الذين يحجزون من مصدرهم الواحد `BookingStaff`.
        // الإدارة أُبلغت بالسداد أعلاه، فالتنبيه بالحجز هنا للموظّفين وحدهم كما كان.
        foreach (BookingStaff::recipients()->reject(fn (User $u) => $u->isAdmin()) as $employee) {
            Notify::send($employee->id, 'cal', 't-amber', "الاستشارة ({$consult->ref}) سُدّدت — احجز موعد جلستها ليُعتمد ويُرسل للعميل.");
        }

        Live::push(new ConsultStatusBroadcast($consult));
        if ($consult->ticket !== null) {
            Live::push(new TicketStatusBroadcast($consult->ticket));
        }
    }
}
