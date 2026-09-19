<?php

namespace App\Console\Commands;

use App\Mail\ExecutionEventMail;
use App\Models\Execution;
use App\Models\Invoice;
use App\Services\MailService;
use App\Support\Notify;
use Illuminate\Console\Command;

/**
 * تذكير بسداد فواتير أتعاب التنفيذ المتأخّرة — طبقة واحدة لكلّ فاتورة، بعد تجاوزها استحقاقها.
 *
 * **الختم على الفاتورة لا على الملفّ.** كان الأمر يمسح الطلبات في المرحلة 6 ويختم
 * `executions.payment_reminder_sent_at`: ختمٌ واحد يكفي حين كانت الفاتورة واحدة. وقد صار
 * لملفّ التنفيذ فواتيرُ عدّة — ثلاث دفعاتٍ في خطّة التقسيط تستحقّ في المراحل 6 و7 و8،
 * وفاتورةُ أتعابٍ مع كلّ تحصيل في النموذج النسبيّ (المرحلة 8) — فبالمنطق القديم يُلاحَق
 * أوّلُها ولا يُلاحَق ما بعده، ويسقط كلُّ ما استحقّ بعد المرحلة 6.
 *
 * **والملاحقة بالاستحقاق لا بعمر الإصدار.** الترشيح بـ`created_at` كان خطأً فادحاً: دفعات
 * الخطّة الثلاث تُنشأ في معاملةٍ واحدة باستحقاقات +3 و+30 و+60 يوماً، فيتلقّى العميل في
 * اليوم الثاني ثلاثة تذكيرات، اثنان عن مالٍ لا يستحقّ قبل شهر. ولأنّ الختم لمرّةٍ واحدة
 * كان ذلك الإنذارُ السابق لأوانه هو **الوحيد** الذي تنالانه — فلا تُلاحَقان حين تتأخّران
 * فعلاً. والمقياس الآن `Invoice::isOverdue()` نفسه الذي تعرضه صفحة الفواتير والمحاسبة،
 * فما يقرؤه العميل «متأخرة» هو ما يُلاحَق عليه.
 *
 * والأمر يعمل كلّ نصف ساعة (‏`routes/console.php`) فالختم حمولةٌ لا زينة: بدونه ثمانيةٌ
 * وأربعون تذكيراً في اليوم عن الفاتورة الواحدة. و`withoutOverlapping` يكفي ضدّ التزامن.
 */
class SendExecInvoiceReminders extends Command
{
    protected $signature = 'exec:send-payment-reminders';

    protected $description = 'إرسال تذكيرات سداد فواتير أتعاب التنفيذ المستحقة';

    public function handle(MailService $mail): int
    {
        // يومُ الاستحقاق نفسه ليس تأخّراً (حتى نهايته) — نفس حدّ `Invoice::isOverdue()`
        $invoices = Invoice::with('user')
            ->whereNotNull('exec_id')->where('paid', false)
            ->whereNull('reminder_sent_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now()->startOfDay())
            ->orderBy('id')
            ->get();

        $sent = 0;

        foreach ($invoices as $invoice) {
            $exec = Execution::find($invoice->exec_id);

            // الملفّ المنتهي لا يُلاحَق عليه سداد — والفاتورة تبقى في دفتره بحالتها
            if ($exec === null || $exec->isClosed() || $invoice->user === null) {
                continue;
            }

            // الحدّ الصريح احتياطاً: `due_at` تاريخٌ بلا وقت، والمقياس المعروض للعميل هو هذا
            if (! $invoice->isOverdue()) {
                continue;
            }

            $which = $invoice->installment_no ? "الدفعة {$invoice->installment_no} — " : '';
            Notify::send(
                $invoice->user_id, 'card', 't-amber',
                "تذكير: {$which}الفاتورة {$invoice->number} على طلب التنفيذ {$exec->number} ("
                .number_format((int) $invoice->amount).' ريال) تجاوزت استحقاقها ولمّا تُسدَّد.'
            );
            // الفاتورة تُمرَّر للبريد فيُذكر رقمها ومبلغها واستحقاقها — وإلّا تطابقت رسائل الدفعات
            $mail->send($invoice->user, new ExecutionEventMail($exec, 'paymentReminder', 'client', $invoice));
            $invoice->forceFill(['reminder_sent_at' => now()])->save();
            $sent++;
        }

        $this->info('أُرسل '.$sent.' تذكير سداد تنفيذ.');

        return self::SUCCESS;
    }
}
