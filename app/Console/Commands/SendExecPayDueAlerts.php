<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Mail\ExecutionEventMail;
use App\Models\Execution;
use App\Models\User;
use App\Services\MailService;
use App\Support\Notify;
use Illuminate\Console\Command;

/**
 * **انقضت مهلة الوفاء ولم تُتَّخذ إجراءات** (قرار المالك 2026-09-12).
 *
 * بعد الإبلاغ بأمر التنفيذ تبدأ مهلة الوفاء (`ExecFlow::payDueAfter`)، وبانقضائها يُطلب اتّخاذ
 * إجراءات عدم الوفاء في ناجز. وكان انقضاؤها يمرّ صامتاً: لا يعرفه المحامي ولا الإدارة إلا
 * بفتح الملفّ. يُرسَل مرّة واحدة لكلّ ملفّ (`pay_due_alert_sent_at`).
 */
class SendExecPayDueAlerts extends Command
{
    protected $signature = 'exec:send-paydue-alerts';

    protected $description = 'تنبيه المكتب بانقضاء مهلة الوفاء على ملفّات التنفيذ بلا إجراءات';

    public function handle(MailService $mail): int
    {
        $executions = Execution::with('assignedLawyer')
            ->where('stage', 8)
            ->whereNotNull('pay_due_at')
            ->where('pay_due_at', '<', now()->startOfDay())
            ->whereNull('pay_due_alert_sent_at')
            ->get()
            // إجراءات مسجّلة ⇒ المهلة انقضت وعُولجت، فلا تنبيه
            ->filter(fn (Execution $e) => empty($e->measures));

        $admins = User::where('role', Role::Admin)->get();
        $sent = 0;

        foreach ($executions as $exec) {
            $due = $exec->pay_due_at?->locale('ar')->translatedFormat('j F Y') ?? '';
            $body = "انقضت مهلة الوفاء ({$due}) على ملفّ التنفيذ {$exec->number} ولم تُسجَّل إجراءات عدم الوفاء.";

            if ($exec->assigned_lawyer_id !== null) {
                Notify::send($exec->assigned_lawyer_id, 'alert', 't-amber', $body);
                $mail->send($exec->assignedLawyer, new ExecutionEventMail($exec, 'payDueOverdue', 'lawyer'));
            }

            foreach ($admins as $admin) {
                Notify::send($admin->id, 'alert', 't-amber', $body);
            }

            if ($admins->isNotEmpty()) {
                $mail->send($admins->all(), new ExecutionEventMail($exec, 'payDueOverdue', 'admin'));
            }

            $exec->update(['pay_due_alert_sent_at' => now()]);
            $sent++;
        }

        $this->info('أُرسل '.$sent.' تنبيه انقضاء مهلة وفاء.');

        return self::SUCCESS;
    }
}
