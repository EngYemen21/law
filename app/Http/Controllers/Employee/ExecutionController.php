<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Concerns\BranchScoped;
use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * دور الموظف مع طلبات التنفيذ — متابعة وتنسيق والتواصل مع العميل.
 */
class ExecutionController extends Controller
{
    use BranchScoped;

    public function index(): Response
    {
        $execs = Execution::with('user')->where('branch', $this->currentBranch())->latest('id')->get()->map(fn (Execution $e) => [
            'no' => $e->number,
            'client' => Ticket::maskClient($e->user?->name ?? ''),
            'subject' => $e->subject,
            'lawyer' => $e->assigned_lawyer ?: '—',
            'status' => $e->status,
            'tone' => $e->tone,
            'last' => $e->last_action,
        ]);

        return Inertia::render('employee/execs', ['execs' => $execs]);
    }

    public function show(Execution $execution): Response
    {
        $this->guardBranch($execution);
        $execution->load(['user', 'procedures']);

        return Inertia::render('employee/exec', [
            'exec' => [
                'no' => $execution->number, 'client' => Ticket::maskClient($execution->user?->name ?? ''),
                'subject' => $execution->subject, 'lawyer' => $execution->assigned_lawyer ?: '—',
                'court' => $execution->court, 'status' => $execution->status, 'tone' => $execution->tone, 'last' => $execution->last_action,
            ],
            'channel' => 'exec.'.$execution->id,
            'messages' => $execution->messages->where('who', '!=', 'note')->values()->map->toMessage(),
            'procedures' => $execution->procedures->map->toData(),
        ]);
    }

    // ردّ خدمة العملاء للعميل داخل طلب التنفيذ (بثّ لحظي)
    public function reply(Request $request, Execution $execution): HttpResponse
    {
        $this->guardBranch($execution);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $execution->messages()->create([
            'who' => 'staff', 'name' => $request->user()->name, 'role' => 'خدمة العملاء',
            'body' => nl2br(e($data['body'])), 'time_label' => $this->clock(),
        ]);

        return response()->noContent();
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
