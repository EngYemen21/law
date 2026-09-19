<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Jobs\AssignTicketJob;
use App\Models\Consult;
use App\Models\Execution;
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
                $activeExecutionsCount = Execution::where('assigned_lawyer_id', $u->id)
                    ->whereNotIn('status', Execution::CLOSED_STATUSES)
                    ->count();
                $activeConsultsCount = Consult::where('assigned_lawyer_id', $u->id)
                    ->whereNotIn('status', ['منتهية', 'لم يحضر', 'ملغاة'])
                    ->count();

                $totalLoad = $activeTicketsCount + ($activeCasesCount * 2) + ($activeExecutionsCount * 2) + $activeConsultsCount;
                $capacityStatus = $totalLoad < 5 ? 'available' : ($totalLoad <= 14 ? 'moderate' : 'busy');

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'department' => $u->department ?: 'الاستشارات العامة',
                    'jobTitle' => $u->job_title ?: 'مستشار قانوني ومحامٍ',
                    'initials' => $u->avatar_initials ?: 'مح',
                    'distributionMode' => $u->distribution_mode ?: 'auto',
                    'activeTicketsCount' => $activeTicketsCount,
                    'activeCasesCount' => $activeCasesCount,
                    'activeExecutionsCount' => $activeExecutionsCount,
                    'activeConsultsCount' => $activeConsultsCount,
                    'totalLoad' => $totalLoad,
                    'capacityStatus' => $capacityStatus,
                ];
            });

        // 1. التذاكر والطلبات
        $tickets = Ticket::with(['user', 'assignedLawyer'])
            ->whereNotIn('status', self::CLOSED)
            ->where('is_frozen', false)
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
                    'itemKind' => 'ticket',
                    'itemKindLabel' => 'تذكرة طلب',
                    'badgeTone' => 'b-blue',
                ];
            });

        // 2. القضايا القضائية
        $cases = LegalCase::with(['user', 'assignedLawyer'])
            ->whereNotIn('status', ['مغلقة', 'مؤرشفة'])
            ->latest('id')
            ->get()
            ->map(function (LegalCase $c) {
                return [
                    'id' => $c->id,
                    'no' => $c->number,
                    'client' => Ticket::maskClient($c->user?->name ?? ''),
                    'realClientName' => $c->user?->name ?? 'عميل المنصة',
                    'userAvatar' => $c->user?->avatar_initials ?: 'عم',
                    'type' => $c->type ?: 'قضية قضائية',
                    'subject' => ($c->type ?: 'قضية').' — '.($c->department ?: 'المحكمة'),
                    'dept' => $c->department ?: 'المحاكم القضائية',
                    'priority' => 'عالية',
                    'lawyer' => $c->assigned_lawyer ?: '—',
                    'lawyerId' => $c->assigned_lawyer_id,
                    'status' => $c->status,
                    'tone' => $c->tone ?: 'b-amber',
                    'date' => $c->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
                    'createdAt' => $c->created_at?->format('Y-m-d H:i'),
                    'caseRef' => $c->number,
                    'claimAmount' => $c->fee ? number_format((int) $c->fee).' ر.س' : null,
                    'courtName' => $c->department,
                    'suggestedLawyerId' => null,
                    'suggestedLawyerName' => null,
                    'itemKind' => 'case',
                    'itemKindLabel' => 'قضية قضائية',
                    'badgeTone' => 'b-amber',
                ];
            });

        // 3. ملفات التنفيذ
        $executions = Execution::with(['user', 'assignedLawyer'])
            ->whereNotIn('status', Execution::CLOSED_STATUSES)
            ->where(function ($q) {
                $q->whereNull('decision')->orWhere('decision', '!=', 'مرفوض');
            })
            ->latest('id')
            ->get()
            ->map(function (Execution $e) {
                return [
                    'id' => $e->id,
                    'no' => $e->number,
                    'client' => Ticket::maskClient($e->user?->name ?? ''),
                    'realClientName' => $e->user?->name ?? 'عميل المنصة',
                    'userAvatar' => $e->user?->avatar_initials ?: 'عم',
                    'type' => 'تنفيذ أحكام وسندات',
                    'subject' => $e->subject ?: 'سند تنفيذي',
                    'dept' => $e->court ?: 'محكمة التنفيذ',
                    'priority' => 'عالية',
                    'lawyer' => $e->assigned_lawyer ?: '—',
                    'lawyerId' => $e->assigned_lawyer_id,
                    'status' => $e->status,
                    'tone' => $e->tone ?: 'b-purple',
                    'date' => $e->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
                    'createdAt' => $e->created_at?->format('Y-m-d H:i'),
                    'caseRef' => $e->najiz_request_no,
                    'claimAmount' => $e->amount ? number_format((int) $e->amount).' ر.س' : null,
                    'courtName' => $e->court,
                    'suggestedLawyerId' => null,
                    'suggestedLawyerName' => null,
                    'itemKind' => 'execution',
                    'itemKindLabel' => 'ملف تنفيذ',
                    'badgeTone' => 'b-purple',
                ];
            });

        // 4. الاستشارات والجلسات
        $consults = Consult::with(['user', 'assignedLawyer'])
            ->whereNotIn('status', array_merge(Consult::TERMINAL_STATUSES, Consult::PRE_SESSION_STATUSES))
            ->where('session', '!=', 'جلسة جارية')
            ->latest('id')
            ->get()
            ->map(function (Consult $cn) {
                return [
                    'id' => $cn->id,
                    'no' => $cn->ref,
                    'client' => Ticket::maskClient($cn->user?->name ?? ''),
                    'realClientName' => $cn->user?->name ?? 'عميل المنصة',
                    'userAvatar' => $cn->user?->avatar_initials ?: 'عم',
                    'type' => $cn->type ?: 'استشارة نظامية',
                    'subject' => $cn->subject ?: 'جلسة استشارية',
                    'dept' => $cn->specialty ?: 'الاستشارات العامة',
                    'priority' => $cn->priority ?: 'متوسطة',
                    'lawyer' => $cn->lawyer ?: '—',
                    'lawyerId' => $cn->assigned_lawyer_id,
                    'status' => $cn->status,
                    'tone' => 'b-teal',
                    'date' => $cn->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
                    'createdAt' => $cn->created_at?->format('Y-m-d H:i'),
                    'caseRef' => null,
                    'claimAmount' => $cn->total ? number_format((int) $cn->total).' ر.س' : null,
                    'courtName' => $cn->channel ?: 'مرئية',
                    'suggestedLawyerId' => null,
                    'suggestedLawyerName' => null,
                    'itemKind' => 'consult',
                    'itemKindLabel' => 'جلسة استشارة',
                    'badgeTone' => 'b-teal',
                ];
            });

        $allDepts = collect([...$tickets, ...$cases, ...$executions, ...$consults])
            ->groupBy('dept')
            ->map(fn ($group, $name) => [
                'name' => $name ?: 'القسم العام',
                'count' => $group->count(),
            ])->values()->all();

        $unassignedTicketsCount = $tickets->where('lawyer', '—')->count();
        $assignedTicketsCount = $tickets->count() - $unassignedTicketsCount;
        $urgentCount = $tickets->filter(fn (array $t) => TicketJourney::isUrgent($t['priority']))->count();

        $unassignedCasesCount = $cases->where('lawyer', '—')->count();
        $unassignedExecsCount = $executions->where('lawyer', '—')->count();
        $unassignedConsultsCount = $consults->where('lawyer', '—')->count();

        $kpis = [
            'total' => $tickets->count(),
            'unassigned' => $unassignedTicketsCount,
            'assigned' => $assignedTicketsCount,
            'urgent' => $urgentCount,
            'activeLawyersCount' => $lawyers->count(),
            'availableLawyersCount' => $lawyers->where('capacityStatus', 'available')->count(),
            // إحصائيات التوزيع الشامل لكافة الأعمال
            'ticketsTotal' => $tickets->count(),
            'ticketsUnassigned' => $unassignedTicketsCount,
            'casesTotal' => $cases->count(),
            'casesUnassigned' => $unassignedCasesCount,
            'executionsTotal' => $executions->count(),
            'executionsUnassigned' => $unassignedExecsCount,
            'consultsTotal' => $consults->count(),
            'consultsUnassigned' => $unassignedConsultsCount,
            'grandTotal' => $tickets->count() + $cases->count() + $executions->count() + $consults->count(),
            'grandUnassigned' => $unassignedTicketsCount + $unassignedCasesCount + $unassignedExecsCount + $unassignedConsultsCount,
        ];

        return Inertia::render('admin/distribute', [
            'tickets' => $tickets,
            'cases' => $cases,
            'executions' => $executions,
            'consults' => $consults,
            'lawyers' => $lawyers,
            'departments' => $allDepts,
            'kpis' => $kpis,
        ]);
    }

    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_if($ticket->is_frozen, 422, 'التذكرة مجمّدة لاعتماد مسارها النهائي — لا يُعاد إسنادها.');
        abort_if(in_array($ticket->status, self::CLOSED, true), 422, 'التذكرة مغلقة — لا يُعاد إسنادها.');

        $data = $request->validate([
            'lawyer_id' => ['required', 'integer', new ActiveLawyer],
        ]);
        $lawyer = User::findOrFail($data['lawyer_id']);

        // الإسناد وقفزة «محالة» من مصدرٍ واحد مع الإسناد الآليّ، والقفزة بالمحرّك باسم الإداريّ
        TicketAssignment::write($ticket, $lawyer->id, $lawyer->name, $request->user());
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

    public function assignCase(Request $request, LegalCase $case): RedirectResponse
    {
        abort_if(in_array($case->status, ['مغلقة', 'مؤرشفة'], true), 422, 'القضية مغلقة أو مؤرشفة — لا يُعاد إسنادها.');

        $data = $request->validate([
            'lawyer_id' => ['required', 'integer', new ActiveLawyer],
        ]);
        $lawyer = User::findOrFail($data['lawyer_id']);

        $case->update([
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
        ]);

        $case->messages()->create([
            'who' => 'note',
            'name' => $request->user()->name,
            'role' => 'توزيع',
            'body' => '<p>أسندت الإدارة القضية إلى '.e($lawyer->name).'.</p>',
            'time_label' => 'الآن',
        ]);

        Audit::log(
            action: 'إسناد قضية',
            description: "أسندت الإدارة ({$request->user()->name}) القضية {$case->number} إلى {$lawyer->name}.",
            category: 'قضايا',
            auditable: $case,
            auditableRef: $case->number,
            afterState: ['المحامي' => $lawyer->name],
        );

        return back()->with('flash', "تم إسناد القضية {$case->number} إلى {$lawyer->name}.");
    }

    public function assignExecution(Request $request, Execution $execution): RedirectResponse
    {
        abort_if($execution->isClosed(), 422, 'ملفّ التنفيذ منتهٍ أو مغلق — لا يُسنَد بعد إغلاقه.');
        abort_if($execution->decision === 'مرفوض', 422, 'هذا الطلب مرفوض بعد الدراسة — لا يُسنَد إليه محامٍ.');

        $data = $request->validate([
            'lawyer_id' => ['required', 'integer', new ActiveLawyer],
        ]);
        $lawyer = User::findOrFail($data['lawyer_id']);

        $execution->update([
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
        ]);

        Audit::log(
            action: 'إسناد ملف تنفيذ',
            description: "أسندت الإدارة ({$request->user()->name}) ملف التنفيذ {$execution->number} إلى {$lawyer->name}.",
            category: 'تنفيذ',
            auditable: $execution,
            auditableRef: $execution->number,
            afterState: ['المحامي' => $lawyer->name],
        );

        return back()->with('flash', "تم إسناد ملف التنفيذ {$execution->number} إلى {$lawyer->name}.");
    }

    public function assignConsult(Request $request, Consult $consult): RedirectResponse
    {
        abort_if(in_array($consult->status, Consult::TERMINAL_STATUSES, true), 422, 'الاستشارة انتهت أو أُلغيت — لا تُحال إلى محامٍ.');
        abort_if(in_array($consult->status, Consult::PRE_SESSION_STATUSES, true), 422, 'الاستشارة ما زالت في دورة الحجز والسداد — لا تُحال للمحامي قبل حجزها.');
        abort_if($consult->session === 'جلسة جارية', 422, 'الجلسة منعقدة الآن — أنهِها قبل تغيير المستشار.');

        $data = $request->validate([
            'lawyer_id' => ['required', 'integer', new ActiveLawyer],
        ]);
        $lawyer = User::findOrFail($data['lawyer_id']);

        $consult->update([
            'assigned_lawyer_id' => $lawyer->id,
            'lawyer' => $lawyer->name,
        ]);

        Audit::log(
            action: 'إسناد جلسة استشارة',
            description: "أسندت الإدارة ({$request->user()->name}) الاستشارة {$consult->ref} إلى {$lawyer->name}.",
            category: 'استشارات',
            auditable: $consult,
            auditableRef: $consult->ref,
            afterState: ['المستشار' => $lawyer->name],
        );

        return back()->with('flash', "تم إسناد الاستشارة {$consult->ref} إلى {$lawyer->name}.");
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
