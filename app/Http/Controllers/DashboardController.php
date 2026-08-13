<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * لوحات المعلومات — عدّادات حقيقية من قاعدة البيانات لكل دور (بدل الأرقام الثابتة).
 */
class DashboardController extends Controller
{
    private const CLOSED = ['مكتملة', 'مغلقة'];

    // لوحة العميل — عدّاداته ورحلة آخر تذكرة له
    public function client(Request $request): Response
    {
        $uid = $request->user()->id;
        $last = Ticket::where('user_id', $uid)->latest('id')->first();

        return Inertia::render('dashboard', [
            'name' => $request->user()->name,
            'counts' => [
                'openTickets' => Ticket::where('user_id', $uid)->whereNotIn('status', self::CLOSED)->count(),
                'upAppts' => Appointment::where('user_id', $uid)->where('when_kind', 'up')->count(),
                'upMeet' => Meeting::where('user_id', $uid)->whereIn('status', ['قادم', 'جارٍ'])->count(),
                'dueInv' => Invoice::where('user_id', $uid)->where('paid', false)->count(),
                'myExec' => Execution::where('user_id', $uid)
                    ->where(fn ($q) => $q->whereNull('stage')->orWhere('stage', '<', 9))
                    ->count(),
            ],
            // أقرب 3 مواعيد قادمة و3 فواتير مستحقّة — لبطاقتَي «مواعيدك القادمة» و«فواتير بانتظار السداد»
            'upcomingAppts' => Appointment::where('user_id', $uid)->where('when_kind', 'up')
                ->with(['user', 'consult'])->latest('id')->take(3)->get()
                ->map(fn (Appointment $a) => $a->toCard())->values(),
            'dueInvoices' => Invoice::where('user_id', $uid)->where('paid', false)
                ->latest('id')->take(3)->get()
                ->map(fn (Invoice $i) => $i->toCard())->values(),
            'lastTicket' => $last ? ['no' => $last->number, 'step' => TicketJourney::indexOf($last->status)] : null,
        ]);
    }

    // لوحة الموظف — التذاكر التي تحتاج إجراءً + عدّادات (محصورة بفرع الموظف)
    public function employee(Request $request): Response
    {
        $branch = $request->user()->branch;
        $active = Ticket::with('user')->where('branch', $branch)
            ->whereNotIn('status', self::CLOSED)->latest('id')->get();

        return Inertia::render('employee/dashboard', [
            'tickets' => $active->map(fn (Ticket $t) => $t->toEmployeeCard()),
            'counts' => [
                'needAction' => $active->count(),
                'missingDocs' => $active->where('status', 'بانتظار مستندات')->count(),
                'todayAppts' => Appointment::where('when_kind', 'up')->count(),
                'referred' => Ticket::where('branch', $branch)->where('status', 'محالة للقسم القانوني')->count(),
            ],
        ]);
    }

    // لوحة الإدارة — نظرة شاملة حقيقية
    public function admin(): Response
    {
        // أحدث نشاط: آخر تذاكر/قضايا/استشارات
        $activity = collect()
            ->concat(Ticket::latest('id')->take(4)->get()->map(fn ($t) => ['ico' => 'folder', 'title' => 'تذكرة جديدة '.$t->number, 'sub' => $t->type]))
            ->concat(LegalCase::latest('id')->take(3)->get()->map(fn ($c) => ['ico' => 'scale', 'title' => 'قضية '.$c->number, 'sub' => $c->status]))
            ->concat(Consult::latest('id')->take(3)->get()->map(fn ($c) => ['ico' => 'video', 'title' => 'استشارة '.$c->ref, 'sub' => $c->channel]))
            ->take(8)->values();

        return Inertia::render('admin/dashboard', [
            'stats' => [
                'clients' => User::where('role', Role::Client)->count(),
                'openTickets' => Ticket::whereNotIn('status', self::CLOSED)->count(),
                'revenue' => (int) Invoice::where('paid', true)->sum('amount'),
                'pendingMeetings' => Meeting::where('approve', '!=', 'معتمد')->count(),
            ],
            'activity' => $activity,
        ]);
    }
}
