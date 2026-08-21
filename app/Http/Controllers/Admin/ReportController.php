<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تقارير وإيرادات الإدارة العليا — تجميعات حقيقية من قاعدة البيانات بدل الأرقام الثابتة.
 */
class ReportController extends Controller
{
    // اختصار أسماء الأقسام للرسم البياني
    private const DEPT_SHORT = [
        'القسم التجاري' => 'تجاري', 'القسم العمالي' => 'عمالي', 'القسم العقاري' => 'عقاري',
        'الأحوال الشخصية' => 'أحوال', 'التنفيذ' => 'تنفيذ',
    ];

    public function reports(): Response
    {
        $total = Ticket::count();
        $closed = Ticket::whereIn('status', ['مكتملة', 'مغلقة'])->count();

        // التذاكر حسب القسم (أعلى 6)
        $byDept = Ticket::selectRaw('COALESCE(NULLIF(department, ""), "غير مصنّف") as dept, COUNT(*) as c')
            ->groupBy('dept')->orderByDesc('c')->limit(6)->get()
            ->map(fn ($r) => ['m' => self::DEPT_SHORT[$r->dept] ?? $r->dept, 'v' => (int) $r->c])->values();

        return Inertia::render('admin/reports', [
            'stats' => [
                'totalTickets' => $total,
                'closureRate' => $total ? (int) round($closed / $total * 100) : 0,
                'meetingsHeld' => Meeting::where('status', 'منتهٍ')->count(),
                'activeCases' => LegalCase::whereIn('status', ['قيد التحضير', 'منظورة'])->count(),
            ],
            'byDept' => $byDept,
        ]);
    }

    public function revenue(): Response
    {
        // إيرادات الاستشارات المدفوعة فقط (بعد السداد) — لا تُحتسب الطلبات المسعّرة بلا سداد.
        // تُحسب في SQL: تحميل كل الاستشارات في الذاكرة كان يجرّ ~45 عموداً منها transcript
        // وzoom_participants_log وai_summary (TEXT) لمجرّد جمع عمودين.
        $paid = Consult::whereNotNull('paid_at');
        $bookings = (clone $paid)->count();
        $bookingRevenue = (int) (clone $paid)->sum('total');

        // الفواتير الحقيقية (تُصدر عند تحديد أتعاب القضية)
        $issued = (int) Invoice::sum('amount');
        $collected = (int) Invoice::where('paid', true)->sum('amount');

        // الإيراد حسب نوع الاستشارة (شامل الضريبة) — بالريال كاملاً:
        // القسمة على 1000 كانت تُصفّر كل إيراد دون 500 ر.س، والرسم نسبيّ لأكبر قيمة أصلاً.
        $byService = (clone $paid)->selectRaw('channel, SUM(total) AS revenue')
            ->groupBy('channel')->get()
            ->map(fn ($row) => ['m' => $row->channel ?: 'أخرى', 'v' => (int) $row->revenue])
            ->values();

        // رواتب الموظفين الثابتة (الموظفون النشطون فعلاً)
        $staff = User::whereIn('role', [Role::Employee, Role::Lawyer])
            ->where('status', '!=', 'suspended')->where('salary', '>', 0)
            ->orderByDesc('salary')->get(['name', 'salary']);

        return Inertia::render('admin/revenue', [
            'bookings' => $bookings,
            'bookingRevenue' => $bookingRevenue,
            'issued' => $issued,
            'collected' => $collected,
            'due' => $issued - $collected,
            'byService' => $byService,
            'salaries' => $staff->map(fn ($u) => ['name' => $u->name, 'salary' => (int) $u->salary])->values(),
            'salaryTotal' => (int) $staff->sum('salary'),
        ]);
    }
}
