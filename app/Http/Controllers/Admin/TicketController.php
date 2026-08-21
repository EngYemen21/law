<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Services\LegalAiService;
use App\Support\Live;
use App\Support\Notify;
use App\Support\Paginate;
use App\Support\TicketJourney;
use App\Support\TicketResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إشراف الإدارة العليا — رؤية كاملة لكل التذاكر وملخصاتها ومسار معالجتها وفلترتها.
 */
class TicketController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $dept = trim((string) $request->query('dept', ''));
        $lawyerId = trim((string) $request->query('lawyer_id', ''));
        $priority = trim((string) $request->query('priority', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $sort = trim((string) $request->query('sort', 'latest'));

        $query = Ticket::with(['user', 'summary', 'assignedLawyer']);

        // 1. البحث النصي
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhere('opponent_name', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('national_id', 'like', "%{$search}%");
                    });
            });
        }

        // 2. الفلترة بحسب الحالة
        if ($status === 'open') {
            $query->whereNotIn('status', ['مكتملة', 'مغلقة']);
        } elseif ($status === 'pending_admin') {
            $query->where(function ($q) {
                $q->where('status', 'بانتظار اعتماد الإدارة')
                    ->orWhereHas('summary', fn ($sq) => $sq->where('result_status', 'pending_admin'));
            });
        } elseif ($status === 'completed') {
            $query->where('status', 'مكتملة');
        } elseif ($status !== '') {
            $query->where('status', $status);
        }

        // 3. الفلترة بحسب القسم
        if ($dept !== '') {
            $query->where('department', $dept);
        }

        // 4. الفلترة بحسب المحامي المسند
        if ($lawyerId === 'unassigned') {
            $query->whereNull('assigned_lawyer_id');
        } elseif ($lawyerId !== '') {
            $query->where('assigned_lawyer_id', $lawyerId);
        }

        // 5. الفلترة بحسب الأولوية
        if ($priority !== '') {
            $query->where('priority', $priority);
        }

        // 6. الفلترة بحسب التاريخ
        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // 7. الترتيب
        if ($sort === 'oldest') {
            $query->orderBy('id', 'asc');
        } elseif ($sort === 'priority') {
            $query->orderByRaw("CASE WHEN priority = 'عاجلة' THEN 1 WHEN priority = 'متوسطة' THEN 2 ELSE 3 END")
                ->orderByDesc('id');
        } else {
            $query->latest('id');
        }

        $tickets = $query->paginate(50)->withQueryString();

        // أقسام مميزة
        $departments = Ticket::whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->pluck('department');

        // قائمة المحامين
        $lawyers = User::where('role', Role::Lawyer)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('admin/tickets', [
            'tickets' => Paginate::shape(
                $tickets,
                fn (Ticket $t) => array_merge($t->toEmployeeCard(), [
                    'client' => $t->user?->name ?? '—',
                    'date' => $t->created_at?->format('Y-m-d') ?: '—',
                ])
            ),
            'filters' => [
                'q' => $search,
                'status' => $status,
                'dept' => $dept,
                'lawyer_id' => $lawyerId,
                'priority' => $priority,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'sort' => $sort,
            ],
            'departments' => $departments,
            'lawyers' => $lawyers,
            'summaryStats' => [
                'total' => Ticket::count(),
                'open' => Ticket::whereNotIn('status', ['مكتملة', 'مغلقة'])->count(),
                'pending_admin' => Ticket::where('status', 'بانتظار اعتماد الإدارة')
                    ->orWhereHas('summary', fn ($sq) => $sq->where('result_status', 'pending_admin'))
                    ->count(),
                'completed' => Ticket::where('status', 'مكتملة')->count(),
            ],
        ]);
    }

    public function show(Ticket $ticket): Response
    {
        $ticket->load(['user', 'summary', 'legalCase']);

        return Inertia::render('lawyer/ticketchat', [
            'ticket' => array_merge($ticket->toEmployeeCard(), [
                'caseRef' => $ticket->legalCase?->number,
                // الإدارة ترى الاسم الكامل + الجوال (بطاقة «تفاصيل الطلب»)
                'client' => $ticket->user?->name ?? '—',
                'mobile' => $ticket->user?->phone,
                'openedAt' => $ticket->created_at?->locale('ar')->translatedFormat('j F Y'),
            ]),
            'channel' => 'ticket.'.$ticket->id,
            'messages' => $ticket->messages->map->toMessage(),
            'summary' => $ticket->summary?->toData(),
            // كانت مفقودة ⇒ canConvert صحيح دائماً فيظهر زر التحويل حتى بعد التحويل
            'converted' => (bool) $ticket->legalCase,
            'base' => '/admin',
        ]);
    }

    public function summaries(): Response
    {
        $summaries = Ticket::with(['summary', 'user'])->whereHas('summary')->latest('id')->get()
            ->map(fn (Ticket $t) => array_merge($t->summary->toData(), [
                'type' => $t->type,
                // الإدارة ترى الاسم الكامل
                'client' => $t->user?->name ?? '—',
            ]));

        return Inertia::render('admin/summaries', ['summaries' => $summaries]);
    }

    // الاعتماد النهائي للإدارة → بطاقة النتيجة تصل العميل وتكتمل التذكرة (يطابق tfAdminReview→tfResult)
    public function approveResult(Request $request, Ticket $ticket): RedirectResponse
    {
        $summary = $ticket->summary;
        abort_unless($summary && $summary->result_status === 'pending_admin', 404);

        $summary->update(['result_status' => 'approved']);

        $ack = $ticket->messages()->create([
            'who' => 'admin',
            'name' => 'الإدارة',
            'role' => 'اعتماد',
            'body' => '<p>تم اعتماد ملخص الاستشارة ومحضر الجلسة من الإدارة. تُرسل النتيجة النهائية الآن.</p>',
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($ack));

        $result = $ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => 'النتيجة',
            'body' => TicketResult::card($ticket, $summary),
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($result));

        $ticket->update([
            'status' => 'مكتملة',
            'tone' => TicketJourney::toneFor('مكتملة'),
            'last_message' => 'اكتملت معالجة الطلب، والنتيجة النهائية متاحة في التذكرة',
            'date_label' => 'الآن',
        ]);
        Live::push(new TicketStatusBroadcast($ticket));

        Notify::send($ticket->user_id, 'check', 't-green', "اكتملت معالجة تذكرتك {$ticket->number}، والنتيجة النهائية والتوصيات متاحة داخل التذكرة.");

        return redirect()->route('admin.tickets');
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
