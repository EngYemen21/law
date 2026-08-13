<?php

namespace App\Console\Commands;

use App\Mail\ExecutionEventMail;
use App\Models\Execution;
use App\Services\MailService;
use App\Support\Notify;
use Illuminate\Console\Command;

/**
 * تذكير بسداد فاتورة أتعاب التنفيذ المستحقة (المرحلة 6 — عرض مقبول بانتظار السداد) — طبقة واحدة،
 * تُرسَل مرّة بعد مرور يوم على قبول العرض دون سداد. idempotent عبر payment_reminder_sent_at.
 */
class SendExecInvoiceReminders extends Command
{
    protected $signature = 'exec:send-payment-reminders';

    protected $description = 'إرسال تذكيرات سداد فواتير أتعاب التنفيذ المستحقة';

    public function handle(MailService $mail): int
    {
        $executions = Execution::with('user')
            ->where('stage', 6)->where('paid', false)
            ->whereNull('payment_reminder_sent_at')
            ->where('updated_at', '<=', now()->subDay())
            ->get();

        $sent = 0;

        foreach ($executions as $exec) {
            if (! $exec->user) {
                continue;
            }

            Notify::send($exec->user_id, 'card', 't-amber', "تذكير: فاتورة أتعاب التنفيذ لطلبك {$exec->number} بانتظار السداد.");
            $mail->send($exec->user, new ExecutionEventMail($exec, 'paymentReminder'));
            $exec->update(['payment_reminder_sent_at' => now()]);
            $sent++;
        }

        $this->info('أُرسل '.$sent.' تذكير سداد تنفيذ.');

        return self::SUCCESS;
    }
}
