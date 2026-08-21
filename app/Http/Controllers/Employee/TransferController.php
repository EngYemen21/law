<?php

namespace App\Http\Controllers\Employee;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Support\TicketAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تحويل التذاكر وإدارة أعباء العمل — الموظف يحوّل التذاكر فردياً أو جماعياً.
 */
class TransferController extends Controller
{
    private const CLOSED = ['مكتملة', 'مغلقة'];

    public function index(): Response
    {
        $allTickets = Ticket::with(['user', 'assignedLawyer'])
            ->whereNotIn('status', self::CLOSED)
            ->latest('id')->get();

        $tickets = $allTickets->map(function (Ticket $t) {
            $isUnassigned = ! $t->assigned_lawyer_id || in_array($t->assigned_lawyer, ['', '—', null], true);

            return array_merge($t->toEmployeeCard(), [
                'no' => $t->number,
                'isUnassigned' => $isUnassigned,
                'updatedAgo' => $t->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
                'createdAgo' => $t->created_at?->locale('ar')->diffForHumans() ?? 'الآن',
            ]);
        });

        // محامو المكتب مع حساب أعباء العمل اللحظية ومؤشر السعة
        $lawyers = User::where('role', Role::Lawyer)
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(function (User $u) {
                $ticketsCount = Ticket::where('assigned_lawyer_id', $u->id)
                    ->whereNotIn('status', self::CLOSED)
                    ->count();
                $casesCount = LegalCase::where('assigned_lawyer_id', $u->id)
                    ->whereNotIn('status', ['مغلقة', 'مؤرشفة'])
                    ->count();

                $capacity = $ticketsCount <= 3 ? 'available' : ($ticketsCount <= 6 ? 'moderate' : 'busy');

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'activeTickets' => $ticketsCount,
                    'activeCases' => $casesCount,
                    'capacity' => $capacity,
                ];
            });

        $counts = [
            'total' => $allTickets->count(),
            'unassigned' => $allTickets->filter(fn (Ticket $t) => ! $t->assigned_lawyer_id || in_array($t->assigned_lawyer, ['', '—', null], true))->count(),
            'assigned' => $allTickets->filter(fn (Ticket $t) => (bool) $t->assigned_lawyer_id && ! in_array($t->assigned_lawyer, ['', '—', null], true))->count(),
            'urgent' => $allTickets->filter(fn (Ticket $t) => in_array($t->priority, ['عالية', 'حرجة', 'urgent', 'high']))->count(),
        ];

        $departments = $allTickets->pluck('department')->filter()->unique()->values();

        // سجل آخر التحويلات المنفذة
        $recentTransfers = TicketMessage::with('ticket')
            ->where('role', 'تحويل')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (TicketMessage $m) => [
                'id' => $m->id,
                'ticketNo' => $m->ticket?->number ?? '—',
                'staff' => $m->name,
                'text' => strip_tags($m->body),
                'date' => $m->created_at?->locale('ar')->diffForHumans() ?? 'الآن',
            ]);

        return Inertia::render('employee/transfer', [
            'tickets' => $tickets,
            'lawyers' => $lawyers,
            'counts' => $counts,
            'departments' => $departments,
            'recentTransfers' => $recentTransfers,
        ]);
    }

    public function transfer(Request $request, Ticket $ticket): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'lawyer_id' => ['required', 'integer', new ActiveLawyer],
            'reason' => ['nullable', 'string', 'max:200'],
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

        TicketAssignment::syncRelatedConsults($ticket->fresh());

        $ticket->messages()->create([
            'who' => 'note',
            'name' => $request->user()->name,
            'role' => 'تحويل',
            'body' => '<p>حُوّلت التذكرة من '.e($from).' إلى '.e($lawyer->name).'.'
                .($toDept !== null && $toDept !== $fromDept ? ' القسم: '.e($fromDept ?: '—').' ← '.e($toDept).'.' : '')
                .(! empty($data['reason']) ? ' السبب: '.e($data['reason']).'.' : '').'</p>',
            'time_label' => $this->clock(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'lawyer' => $lawyer->name]);
        }

        return back()->with('flash', "تم تحويل التذكرة {$ticket->number} إلى {$lawyer->name}.");
    }

    /**
     * تحويل جماعي لعدة تذاكر دفعة واحدة
     */
    public function bulkTransfer(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'tickets' => ['required', 'array', 'min:1'],
            'tickets.*' => ['required', 'string'],
            'lawyer_id' => ['required', 'integer', new ActiveLawyer],
            'reason' => ['nullable', 'string', 'max:200'],
            'department' => ['nullable', 'string', 'max:190'],
        ]);

        $lawyer = User::findOrFail($data['lawyer_id']);
        $tickets = Ticket::whereIn('number', $data['tickets'])->get();

        DB::transaction(function () use ($tickets, $lawyer, $data, $request) {
            foreach ($tickets as $ticket) {
                $from = $ticket->assigned_lawyer ?: '—';
                $fromDept = $ticket->department;
                $toDept = $data['department'] ?? null;

                $ticket->update(array_filter([
                    'assigned_lawyer' => $lawyer->name,
                    'assigned_lawyer_id' => $lawyer->id,
                    'department' => $toDept,
                ], fn ($v) => $v !== null));

                TicketAssignment::syncRelatedConsults($ticket->fresh());

                $ticket->messages()->create([
                    'who' => 'note',
                    'name' => $request->user()->name,
                    'role' => 'تحويل',
                    'body' => '<p>تحويل جماعي: نُقلت التذكرة من '.e($from).' إلى '.e($lawyer->name).'.'
                        .($toDept !== null && $toDept !== $fromDept ? ' القسم: '.e($fromDept ?: '—').' ← '.e($toDept).'.' : '')
                        .(! empty($data['reason']) ? ' السبب: '.e($data['reason']).'.' : '').'</p>',
                    'time_label' => $this->clock(),
                ]);
            }
        });

        $count = $tickets->count();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'count' => $count, 'lawyer' => $lawyer->name]);
        }

        return back()->with('flash', "تم تحويل {$count} تذكرة بنجاح إلى {$lawyer->name}.");
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
