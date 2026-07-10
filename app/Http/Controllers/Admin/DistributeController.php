<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * توزيع التذاكر — الإدارة تُسند التذاكر النشطة إلى المحامين (يكتب assigned_lawyer_id فعلياً).
 */
class DistributeController extends Controller
{
    private const CLOSED = ['مكتملة', 'مغلقة'];

    public function index(): Response
    {
        $tickets = Ticket::with('user')->whereNotIn('status', self::CLOSED)->latest('id')->get()
            ->map(fn (Ticket $t) => array_merge($t->toEmployeeCard(), ['no' => $t->number]));

        return Inertia::render('admin/distribute', [
            'tickets' => $tickets,
            'lawyers' => User::where('role', Role::Lawyer)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate(['lawyer_id' => ['required', 'integer', 'exists:users,id']]);
        $lawyer = User::where('role', Role::Lawyer)->findOrFail($data['lawyer_id']);

        $ticket->update(['assigned_lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id]);
        $ticket->messages()->create([
            'who' => 'note', 'name' => $request->user()->name, 'role' => 'توزيع',
            'body' => '<p>أسندت الإدارة التذكرة إلى '.e($lawyer->name).'.</p>', 'time_label' => 'الآن',
        ]);

        return back()->with('flash', "تم إسناد التذكرة {$ticket->number} إلى {$lawyer->name}.");
    }
}
