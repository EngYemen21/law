<?php

namespace App\Http\Controllers\Employee;

use App\Enums\Role;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Rules\ActiveLegalDepartment;
use App\Support\Audit;
use App\Support\LegalCatalogue;
use App\Support\Live;
use App\Support\Notify;
use App\Support\TicketAssignment;
use App\Support\TicketJourney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * تحويل التذاكر وإدارة أعباء العمل — الموظف يحوّل التذاكر فردياً أو جماعياً.
 */
class TransferController extends Controller
{
    public function index(): Response
    {
        // ما يُحوَّل فقط — المجمّدة تُستبعد كقائمة «توزيع التذاكر» (`TicketAssignment::assertReassignable`)
        $allTickets = Ticket::with(['user', 'assignedLawyer'])
            ->distributable()
            ->latest('id')->get();

        // اقتراح النظام لغير المسنَدة، موسوماً بالتخصّص — يؤكّده الموظّف ولا يُكتب شيء (قرار 2026-09-20)
        $suggestions = TicketAssignment::suggestMany($allTickets->whereNull('assigned_lawyer_id'));

        $tickets = $allTickets->map(function (Ticket $t) use ($suggestions) {
            $isUnassigned = ! $t->assigned_lawyer_id || in_array($t->assigned_lawyer, ['', '—', null], true);

            return array_merge($t->toEmployeeCard(), [
                'no' => $t->number,
                'isUnassigned' => $isUnassigned,
                'suggestion' => isset($suggestions[$t->id]) ? $suggestions[$t->id]->toArray() : null,
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
                    ->open()
                    ->count();
                $casesCount = LegalCase::where('assigned_lawyer_id', $u->id)
                    ->active()
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
            'urgent' => $allTickets->filter(fn (Ticket $t) => TicketJourney::isUrgent($t->priority))->count(),
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
            // قسمٌ فعّال من الكتالوج فقط — كان يُقبل أيّ نصٍّ، ومنه أقسامٌ إداريّة كـ«خدمة العملاء»
            'department' => ['nullable', 'string', 'max:190', new ActiveLegalDepartment],
        ]);

        TicketAssignment::assertReassignable($ticket);

        $lawyer = User::whereKey($data['lawyer_id'])->firstOrFail();
        $from = $ticket->assigned_lawyer ?: '—';
        $fromDept = $ticket->department;
        $toDept = LegalCatalogue::fromInput($data['department'] ?? null)?->name;

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

        Audit::log(
            action: 'تحويل تذكرة بين المحامين',
            description: "حوّل {$request->user()->name} التذكرة {$ticket->number} من {$from} إلى {$lawyer->name}.".(! empty($data['reason']) ? " السبب: {$data['reason']}." : ''),
            category: 'تذاكر',
            auditable: $ticket,
            auditableRef: $ticket->number,
            beforeState: ['المحامي' => $from, 'القسم' => $fromDept ?: '—'],
            afterState: ['المحامي' => $lawyer->name, 'القسم' => $toDept ?: ($fromDept ?: '—')],
        );

        Live::push(new TicketStatusBroadcast($ticket));
        TicketAssignment::notifyAssigned($ticket, $lawyer, $request->user());

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
            'department' => ['nullable', 'string', 'max:190', new ActiveLegalDepartment],
        ]);

        $lawyer = User::whereKey($data['lawyer_id'])->firstOrFail();
        $data['department'] = LegalCatalogue::fromInput($data['department'] ?? null)?->name;

        // حارس الفرديّ نفسه لكلّ تذكرة — المرفوضة تُذكر بسببها ولا تُسقط غيرها
        $refused = [];
        $tickets = Ticket::whereIn('number', $data['tickets'])->get()->filter(function (Ticket $ticket) use (&$refused) {
            try {
                TicketAssignment::assertReassignable($ticket);

                return true;
            } catch (HttpExceptionInterface $e) {
                $refused[] = "{$ticket->number}: {$e->getMessage()}";

                return false;
            }
        })->values();

        abort_if($tickets->isEmpty(), 422, 'لم تُحوَّل أيّ تذكرة — '.implode(' · ', $refused));

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

        Audit::log(
            action: 'تحويل جماعي للتذاكر',
            description: "حوّل {$request->user()->name} {$count} تذكرة دفعة واحدة إلى {$lawyer->name} (".$tickets->pluck('number')->implode('، ').').'.(! empty($data['reason']) ? " السبب: {$data['reason']}." : ''),
            category: 'تذاكر',
            severity: 'warning',
            afterState: ['المحامي' => $lawyer->name, 'التذاكر' => $tickets->pluck('number')->all()],
        );

        $tickets->each(fn (Ticket $ticket) => Live::push(new TicketStatusBroadcast($ticket)));
        if ((int) $lawyer->id !== (int) $request->user()->id) {
            Notify::send($lawyer->id, 'folder', 't-blue', "أُسندت إليك {$count} تذكرة: ".$tickets->pluck('number')->implode('، ').'. تابعها من «التذاكر».');
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'count' => $count, 'lawyer' => $lawyer->name, 'refused' => $refused]);
        }

        $response = back()->with('flash', "تم تحويل {$count} تذكرة بنجاح إلى {$lawyer->name}.");

        return $refused === []
            ? $response
            : $response->withErrors(['message' => 'لم تُحوَّل '.count($refused).' تذكرة — '.implode(' · ', $refused)]);
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
