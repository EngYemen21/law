<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Jobs\AssignTicketJob;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Support\Audit;
use App\Support\Live;
use App\Support\TicketAssignment;
use App\Support\TicketJourney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DistributeController extends Controller
{
    private const CLOSED = ['مكتملة', 'مغلقة'];

    public function index(): Response
    {
        $lawyers = User::where('role', Role::Lawyer)
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(function (User $u) {
                $activeTicketsCount = Ticket::where('assigned_lawyer_id', $u->id)
                    ->whereNotIn('status', self::CLOSED)
                    ->count();
                $activeCasesCount = LegalCase::where('assigned_lawyer_id', $u->id)
                    ->whereNotIn('status', ['مغلقة', 'مؤرشفة'])
                    ->count();

                $totalLoad = $activeTicketsCount + ($activeCasesCount * 2);
                $capacityStatus = $totalLoad < 5 ? 'available' : ($totalLoad <= 12 ? 'moderate' : 'busy');

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'department' => $u->department ?: 'الاستشارات العامة',
                    'jobTitle' => $u->job_title ?: 'مستشار قانوني ومحامٍ',
                    'initials' => $u->avatar_initials ?: 'مح',
                    'distributionMode' => $u->distribution_mode ?: 'auto',
                    'activeTicketsCount' => $activeTicketsCount,
                    'activeCasesCount' => $activeCasesCount,
                    'totalLoad' => $totalLoad,
                    'capacityStatus' => $capacityStatus,
                ];
            });

        $tickets = Ticket::with(['user', 'assignedLawyer'])
            ->whereNotIn('status', self::CLOSED)
            ->latest('id')
            ->get()
            ->map(function (Ticket $t) {
                $suggested = TicketAssignment::pickLawyer($t, requireSpecialty: false);

                return [
                    'id' => $t->id,
                    'no' => $t->number,
                    'client' => Ticket::maskClient($t->user?->name ?? ''),
                    'realClientName' => $t->user?->name ?? 'عميل المنصة',
                    'userAvatar' => $t->user?->avatar_initials ?: 'عم',
                    'type' => $t->type ?: 'طلب عام',
                    'subject' => $t->subject ?: 'موضوع التذكرة',
                    'dept' => $t->department ?: 'القسم العام',
                    'priority' => $t->priority ?: 'متوسطة',
                    'lawyer' => $t->assigned_lawyer ?: '—',
                    'lawyerId' => $t->assigned_lawyer_id,
                    'status' => $t->status,
                    'tone' => $t->tone ?: 'b-blue',
                    'date' => $t->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
                    'createdAt' => $t->created_at?->format('Y-m-d H:i'),
                    'caseRef' => $t->case_ref,
                    'claimAmount' => $t->claim_amount,
                    'courtName' => $t->court_name,
                    'suggestedLawyerId' => $suggested?->id,
                    'suggestedLawyerName' => $suggested?->name,
                ];
            });

        $depts = $tickets->groupBy('dept')->map(fn ($group, $name) => [
            'name' => $name ?: 'القسم العام',
            'count' => $group->count(),
        ])->values()->all();

        $unassignedCount = $tickets->where('lawyer', '—')->count();
        $assignedCount = $tickets->count() - $unassignedCount;
        $urgentCount = $tickets->whereIn('priority', ['عالية', 'عاجلة', 'عاجلة جداً'])->count();

        $kpis = [
            'total' => $tickets->count(),
            'unassigned' => $unassignedCount,
            'assigned' => $assignedCount,
            'urgent' => $urgentCount,
            'activeLawyersCount' => $lawyers->count(),
            'availableLawyersCount' => $lawyers->where('capacityStatus', 'available')->count(),
        ];

        return Inertia::render('admin/distribute', [
            'tickets' => $tickets,
            'lawyers' => $lawyers,
            'departments' => $depts,
            'kpis' => $kpis,
        ]);
    }

    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'lawyer_id' => ['required', 'integer', new ActiveLawyer],
        ]);
        $lawyer = User::findOrFail($data['lawyer_id']);

        $updates = [
            'assigned_lawyer' => $lawyer->name,
            'assigned_lawyer_id' => $lawyer->id,
        ];

        if (in_array($ticket->status, ['جديدة', 'قيد التحليل'], true)) {
            $updates['status'] = 'محالة للقسم القانوني';
            $updates['tone'] = TicketJourney::toneFor('محالة للقسم القانوني');
            $updates['last_message'] = 'تمت إحالة طلبكم إلى القسم القانوني المختص لدراسة الموضوع.';
            $updates['date_label'] = 'الآن';
        }

        $ticket->update($updates);
        TicketAssignment::syncRelatedConsults($ticket->fresh());
        Live::push(new TicketStatusBroadcast($ticket));

        $ticket->messages()->create([
            'who' => 'note', 'name' => $request->user()->name, 'role' => 'توزيع',
            'body' => '<p>أسندت الإدارة التذكرة إلى '.e($lawyer->name).'.</p>', 'time_label' => 'الآن',
        ]);

        Audit::log(
            action: 'إسناد تذكرة',
            description: "أسندت الإدارة ({$request->user()->name}) التذكرة {$ticket->number} إلى {$lawyer->name}.",
            category: 'تذاكر',
            auditable: $ticket,
            auditableRef: $ticket->number,
            afterState: ['المحامي' => $lawyer->name],
        );

        return back()->with('flash', "تم إسناد التذكرة {$ticket->number} إلى {$lawyer->name}.");
    }

    public function auto(Request $request): RedirectResponse
    {
        $actorName = $request->user()->name;
        $tickets = Ticket::whereNotIn('status', self::CLOSED)
            ->whereNull('assigned_lawyer_id')
            ->get(['id']);

        foreach ($tickets as $ticket) {
            AssignTicketJob::dispatch($ticket->id, $actorName);
        }

        $n = $tickets->count();
        $msg = $n > 0
            ? "جارٍ توزيع {$n} تذكرة تلقائياً في الخلفية — حدّث الصفحة بعد قليل لرؤية الإسناد."
            : 'لا توجد تذاكر غير مُسندة للتوزيع.';

        return back()->with('flash', $msg);
    }
}
