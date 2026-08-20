<?php

namespace App\Http\Controllers\Employee;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Support\TicketAssignment;
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
    private const CLOSED = ['مكتملة', 'مغلقة'];

    public function index(): Response
    {
        $tickets = Ticket::with('user')
            ->whereNotIn('status', self::CLOSED)->latest('id')->get()
            ->map(fn (Ticket $t) => array_merge($t->toEmployeeCard(), ['no' => $t->number]));

        return Inertia::render('employee/transfer', [
            'tickets' => $tickets,
            'lawyers' => User::where('role', Role::Lawyer)
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function transfer(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            // المحامي الوجهة يجب أن يكون نشطاً (يمنع تمرير عميل/موظف/إداري كـlawyer_id).
            'lawyer_id' => ['required', 'integer', new ActiveLawyer],
            'reason' => ['nullable', 'string', 'max:200'],
            // القسم المختص يُختار في المودال وكان يُهمَل — يُحفظ الآن فعلياً
            'department' => ['nullable', 'string', 'max:190'],
        ]);
        $lawyer = User::findOrFail($data['lawyer_id']);
        $from = $ticket->assigned_lawyer ?: '—';
        $fromDept = $ticket->department;
        $toDept = $data['department'] ?? null;

        $ticket->update(array_filter([
            'assigned_lawyer' => $lawyer->name,
            'assigned_lawyer_id' => $lawyer->id,
            'department' => $toDept,
        ], fn ($v) => $v !== null));
        // انتشار المحامي/الفرع الجديد إلى استشارات التذكرة المفتوحة
        TicketAssignment::syncRelatedConsults($ticket->fresh());
        $ticket->messages()->create([
            'who' => 'note', 'name' => $request->user()->name, 'role' => 'تحويل',
            'body' => '<p>حُوّلت التذكرة من '.e($from).' إلى '.e($lawyer->name).'.'
                .($toDept !== null && $toDept !== $fromDept ? ' القسم: '.e($fromDept ?: '—').' ← '.e($toDept).'.' : '')
                .(! empty($data['reason']) ? ' السبب: '.e($data['reason']).'.' : '').'</p>',
            'time_label' => 'الآن',
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'lawyer' => $lawyer->name]);
        }

        return back()->with('flash', "تم تحويل التذكرة {$ticket->number} إلى {$lawyer->name}.");

    }
}
