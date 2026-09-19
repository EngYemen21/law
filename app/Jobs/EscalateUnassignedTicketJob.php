<?php

namespace App\Jobs;

use App\Enums\Role;
use App\Mail\TicketEscalatedMail;
use App\Models\Ticket;
use App\Models\User;
use App\Services\MailService;
use App\Support\Audit;
use App\Support\Notify;
use App\Support\TicketAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * تذكرة بلا محامٍ متخصّص ⇒ تُسنَد للإدارة العليا مع إشعار داخليّ وبريد.
 *
 * لماذا: كان `pickLawyer` يسقط على **كل** المحامين إن لم يتطابق قسم فيُسنِد الأقلّ حملاً —
 * تذكرة عمالية تُسنَد لمحامي عقارات بلا أن يعلم أحد. إسناد خاطئ صامت أسوأ من تصعيد ظاهر.
 *
 * ولماذا مطابورة: الإشعار والبريد لا يجوز أن يُبطئا فتح التذكرة (ولا أن يُسقطاه إن تعذّر
 * البريد) — نفس انتظام TriageTicketOnOpenJob وAssignTicketJob.
 */
class EscalateUnassignedTicketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** الصلاحية التي تُخوّل إسناد التذاكر يدوياً (شاشة التوزيع). */
    private const DISTRIBUTE_PERMISSION = 'توزيع التذاكر';

    /** الاسم المعروض في حقل المحامي — لا اسم شخص: التذكرة مُصعَّدة لا مُسنَدة لمحامٍ. */
    public const SENIOR_LABEL = 'الإدارة العليا';

    public function __construct(public int $ticketId) {}

    public function handle(MailService $mail): void
    {
        $ticket = Ticket::find($this->ticketId);

        // أُسنِدت يدوياً بين الإرسال والتنفيذ (سباق مشروع) ⇒ لا تُصعَّد
        if ($ticket === null || $ticket->assigned_lawyer_id) {
            return;
        }

        // أقدم إداريّ: اختيار حتميّ يطابق تكسير التعادل في بقيّة المشروع (أقدم معرّفاً)
        $senior = User::where('role', Role::Admin)->orderBy('id')->first();
        if ($senior === null) {
            return; // لا إدارة بعد (تثبيت جديد) — لا شيء يُصعَّد إليه
        }

        // قفل قصير + إعادة فحص: تمنع الكتابة فوق إسناد يدويّ وقع في نفس اللحظة
        DB::transaction(function () use ($senior): void {
            $locked = Ticket::whereKey($this->ticketId)->lockForUpdate()->first();
            if ($locked === null || $locked->assigned_lawyer_id) {
                return;
            }
            // نفس قفزة الحالة في TicketAssignment::assign (ومن مصدرها نفسه): التذكرة أُحيلت فعلاً
            // (للإدارة بدل محامٍ)، وبقاؤها عند «قيد التحليل» يجعلها تبدو عالقة للعميل إلى الأبد.
            TicketAssignment::write($locked, $senior->id, self::SENIOR_LABEL);
        });

        if ($ticket->fresh()?->assigned_lawyer_id !== $senior->id) {
            return; // فاز إسناد يدويّ بالسباق
        }

        Audit::log(
            action: 'تصعيد تذكرة غير مُسندة',
            description: "صعّد النظام التذكرة {$ticket->number} للإدارة العليا — بقيت بلا محامٍ متخصّص (القسم: ".($ticket->department ?: 'غير محدَّد').').',
            category: 'تذاكر',
            severity: 'warning',
            auditable: $ticket,
            auditableRef: $ticket->number,
        );

        $this->notifyDecisionMakers($ticket->fresh(), $mail);
    }

    /** إشعار داخليّ + بريد لكل من يملك قرار الإسناد. */
    private function notifyDecisionMakers(Ticket $ticket, MailService $mail): void
    {
        $dept = $ticket->department ?: 'غير محدَّد';
        $body = "التذكرة {$ticket->number} بلا محامٍ متخصّص (القسم: {$dept}) — أُسنِدت للإدارة العليا، أعِد إسنادها من شاشة توزيع التذاكر.";

        $admins = User::where('role', Role::Admin)->get();

        // نطاق spatie يرمي إن لم تكن الصلاحية مزروعة (تثبيت جديد/بيئة اختبار بلا كتالوج).
        // التصعيد مساعد لا يجوز أن يُسقط المهمّة — فيُتجاوز حاملو الصلاحية وتبقى الإدارة.
        try {
            $holders = User::permission(self::DISTRIBUTE_PERMISSION)->get();
        } catch (\Throwable) {
            $holders = collect();
        }

        $recipients = $admins->concat($holders)->unique('id');

        foreach ($recipients as $user) {
            Notify::send($user->id, 'user', 't-amber', $body);
        }

        // بريد مستقلّ بعنوان مختلف: الإدارة تستلم أصلاً بريد «تذكرة جديدة» لكل تذكرة،
        // فدمج التصعيد فيه يدفنه في رسالة روتينية تُتجاهَل.
        $mailable = $recipients->filter(fn (User $u) => filled($u->email));
        if ($mailable->isNotEmpty()) {
            $mail->send($mailable->all(), new TicketEscalatedMail($ticket));
        }
    }
}
