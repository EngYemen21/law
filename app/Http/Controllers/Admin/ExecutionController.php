<?php

namespace App\Http\Controllers\Admin;

use App\Events\ExecStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\Ticket;
use App\Models\UserNotification;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إشراف الإدارة على طلبات التنفيذ + الإغلاق والأرشفة.
 */
class ExecutionController extends Controller
{
    public function index(): Response
    {
        $execs = Execution::with('user')->latest('id')->get()->map(fn (Execution $e) => [
            'no' => $e->number,
            'client' => Ticket::maskClient($e->user?->name ?? ''),
            'subject' => $e->subject,
            'lawyer' => $e->assigned_lawyer ?: '—',
            'status' => $e->status,
            'tone' => $e->tone,
            'canClose' => $e->status === 'مكتمل',
        ]);

        return Inertia::render('admin/execs', ['execs' => $execs]);
    }

    // الإغلاق والأرشفة بعد اكتمال التنفيذ
    public function close(Execution $execution): RedirectResponse
    {
        abort_unless($execution->status === 'مكتمل', 422);

        $execution->update(['status' => 'مغلق', 'tone' => 'b-grey', 'last_action' => 'أُغلق طلب التنفيذ وأُرشف']);
        $execution->messages()->create([
            'who' => 'admin', 'name' => 'الإدارة', 'role' => 'إغلاق',
            'body' => '<p>بعد اكتمال التنفيذ والتحصيل، أُغلق طلب التنفيذ وحُفظ ملفه في الأرشيف.</p>',
            'time_label' => $this->clock(),
        ]);
        UserNotification::create([
            'user_id' => $execution->user_id, 'icon' => 'check', 'tone' => 't-green',
            'body' => "أُغلق طلب تنفيذك {$execution->number} وأُرشف بعد اكتمال الإجراءات.",
            'time_label' => 'الآن', 'is_read' => false,
        ]);
        broadcast(new ExecStatusBroadcast($execution));

        return back();
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
