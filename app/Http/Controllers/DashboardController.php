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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

        // القادمة تُشتق من الزمن الحقيقي (liveState) — when_kind المخزّنة لا تتحدّث بمرور الوقت
        $myAppts = Appointment::where('user_id', $uid)->with(['user', 'consult'])->latest('id')->get();
        $upcoming = $myAppts->filter(fn (Appointment $a) => $a->liveState()[0] === 'up')->values();

        // غير المدفوعة مرتّبة بالاستحقاق (الأقرب أولاً، بلا استحقاق آخراً) — كانت بالأحدث إنشاءً
        $unpaid = Invoice::where('user_id', $uid)->where('paid', false)
            ->orderByRaw('due_at IS NULL')->orderBy('due_at')->latest('id')->get();

        return Inertia::render('dashboard', [
            'name' => $request->user()->name,
            'counts' => [
                'openTickets' => Ticket::where('user_id', $uid)->whereNotIn('status', self::CLOSED)->count(),
                'upAppts' => $upcoming->count(),
                // الحالة المخزّنة لا تتحدّث بمرور الوقت — الاشتقاق الحي (liveState) هو الفيصل
                'upMeet' => Meeting::where('user_id', $uid)->get()
                    ->filter(fn (Meeting $m) => $m->isUpcoming())->count(),
                'dueInv' => $unpaid->count(),
                // المتأخرة وحدها (تجاوزت استحقاقها) — كانت مدموجة في «المستحقة» فلا يميّز العميل العاجل
                'overdueInv' => $unpaid->filter(fn (Invoice $i) => $i->isOverdue())->count(),
                'myExec' => Execution::where('user_id', $uid)
                    ->where(fn ($q) => $q->whereNull('stage')->orWhere('stage', '<', 9))
                    ->count(),
            ],
            // أقرب 3 مواعيد قادمة و3 فواتير مستحقّة — لبطاقتَي «مواعيدك القادمة» و«فواتير بانتظار السداد»
            'upcomingAppts' => $upcoming->take(3)->map(fn (Appointment $a) => $a->toCard())->values(),
            'dueInvoices' => $unpaid->take(3)->map(fn (Invoice $i) => $i->toCard())->values(),
            'lastTicket' => $last ? ['no' => $last->number, 'step' => TicketJourney::indexOf($last->status)] : null,
        ]);
    }

    // لوحة الموظف — التذاكر التي تحتاج إجراءً + عدّادات (مكتب واحد: كل التذاكر)
    public function employee(Request $request): Response
    {
        $active = Ticket::with('user')
            ->whereNotIn('status', self::CLOSED)->latest('id')->get();

        return Inertia::render('employee/dashboard', [
            'tickets' => $active->map(fn (Ticket $t) => $t->toEmployeeCard()),
            'counts' => [
                'needAction' => $active->count(),
                'missingDocs' => $active->where('status', 'بانتظار مستندات')->count(),
                // مواعيد قادمة فعلاً (زمنياً) — كان الفلتر يقارن فرع الموظف بمكان الجلسة فلا يطابق شيئاً
                'todayAppts' => Appointment::with('consult')
                    ->whereNotNull('starts_at')->where('starts_at', '>=', now()->subDay())
                    ->get()
                    ->filter(fn (Appointment $a) => $a->liveState()[0] === 'up')->count(),
                'referred' => Ticket::where('status', 'محالة للقسم القانوني')->count(),
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

    /**
     * تصفير بيانات الاختبار — يحذف جميع سجلات الجداول التشغيلية مع الإبقاء على جدول المستخدمين والأدوار.
     */
    public function resetDatabase(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        Schema::disableForeignKeyConstraints();

        $tablesToTruncate = [
            'appointments',
            'case_documents',
            'case_hearings',
            'case_messages',
            'cases',
            'consults',
            'correspondences',
            'documents',
            'execution_documents',
            'execution_messages',
            'execution_procedures',
            'executions',
            'invoices',
            'meet_requests',
            'meetings',
            'payments',
            'tasks',
            'ticket_documents',
            'ticket_messages',
            'ticket_summaries',
            'tickets',
            'user_notifications',
            'jobs',
            'failed_jobs',
            'job_batches',
        ];

        foreach ($tablesToTruncate as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        Schema::enableForeignKeyConstraints();

        // تنظيف الملفات المؤقتة للاختبار
        Storage::disk('local')->deleteDirectory('ticket-docs');
        Storage::disk('local')->deleteDirectory('case-docs');
        Storage::disk('local')->deleteDirectory('executions');

        cache()->flush();

        return redirect()->route('admin.dashboard')->with('flash', 'تم تصفير جميع بيانات الاختبار بنجاح مع الاحتفاظ بالمستخدمين.');
    }
}
