<?php

namespace App\Http\Controllers\Employee;

use App\Enums\Role;
use App\Http\Controllers\Concerns\BranchScoped;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تحويل التذاكر — الموظف يحوّل تذكرة من محامٍ إلى آخر (يكتب assigned_lawyer_id فعلياً).
 * محصور بفرع الموظف: تذاكر فرعه فقط، ومحامو فرعه فقط.
 */
class TransferController extends Controller
{
    use BranchScoped;

    private const CLOSED = ['مكتملة', 'مغلقة'];

    public function index(): Response
    {
        $tickets = Ticket::with('user')->where('branch', $this->currentBranch())
            ->whereNotIn('status', self::CLOSED)->latest('id')->get()
            ->map(fn (Ticket $t) => array_merge($t->toEmployeeCard(), ['no' => $t->number]));

        return Inertia::render('employee/transfer', [
            'tickets' => $tickets,
            'lawyers' => User::where('role', Role::Lawyer)->where('branch', $this->currentBranch())
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function transfer(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guardBranch($ticket);
        $data = $request->validate([
            'lawyer_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $lawyer = User::where('role', Role::Lawyer)->findOrFail($data['lawyer_id']);
        $from = $ticket->assigned_lawyer ?: '—';

        // التحويل ينقل التذكرة لفرع المحامي الجديد (يبقى العزل بالفرع متّسقاً)
        $ticket->update([
            'assigned_lawyer' => $lawyer->name,
            'assigned_lawyer_id' => $lawyer->id,
            'branch' => $lawyer->branch ?: $ticket->branch,
        ]);
        $ticket->messages()->create([
            'who' => 'note', 'name' => $request->user()->name, 'role' => 'تحويل',
            'body' => '<p>حُوّلت التذكرة من '.e($from).' إلى '.e($lawyer->name).'.'
                .(! empty($data['reason']) ? ' السبب: '.e($data['reason']).'.' : '').'</p>',
            'time_label' => 'الآن',
        ]);

        return back()->with('flash', "تم تحويل التذكرة {$ticket->number} إلى {$lawyer->name}.");
    }
}
