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
use App\Support\PdfRenderer;
use App\Support\ReportPrint;
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

    /** تصدير تقرير الأداء PDF — كانت الشاشة بطاقات بلا أي تصدير أو طباعة. */
    public function reportsPdf(): \Symfony\Component\HttpFoundation\Response
    {
        $total = Ticket::count();
        $closed = Ticket::whereIn('status', ['مكتملة', 'مغلقة'])->count();
        $byDept = Ticket::selectRaw('COALESCE(NULLIF(department, ""), "غير مصنّف") as dept, COUNT(*) as c')
            ->groupBy('dept')->orderByDesc('c')->limit(6)->get();

        $html = ReportPrint::html([
            'title' => 'تقرير الأداء العام',
            'subtitle' => 'حتى '.now()->format('Y-m-d'),
            'ref' => 'RPT-'.now()->format('Ymd'),
            'blocks' => [
                [
                    'title' => '١. المؤشرات الرئيسية',
                    'cellRows' => [
                        [['إجمالي التذاكر', (string) $total], ['نسبة الإغلاق', ($total ? (int) round($closed / $total * 100) : 0).'%']],
                        [['اجتماعات منعقدة', (string) Meeting::where('status', 'منتهٍ')->count()], ['قضايا نشطة', (string) LegalCase::whereIn('status', ['قيد التحضير', 'منظورة'])->count()]],
                    ],
                ],
                [
                    'title' => '٢. التذاكر حسب القسم',
                    'cellRows' => $byDept->map(fn ($r) => [[$r->dept, (string) $r->c]])->all(),
                ],
            ],
            'footer' => 'النظام الإداري لمكاتب المحاماة — تقرير داخلي',
        ]);

        return PdfRenderer::render($html, 'reports-'.now()->format('Y-m-d').'.pdf');
    }

    /** تصدير تقرير الإيرادات PDF. */
    public function revenuePdf(): \Symfony\Component\HttpFoundation\Response
    {
        $paid = Consult::whereNotNull('paid_at');
        $issued = (int) Invoice::sum('amount');
        $collected = (int) Invoice::where('paid', true)->sum('amount');
        $byService = (clone $paid)->selectRaw('channel, SUM(total) AS revenue')->groupBy('channel')->get();
        $staff = User::whereIn('role', [Role::Employee, Role::Lawyer])
            ->where('status', '!=', 'suspended')->where('salary', '>', 0)
            ->orderByDesc('salary')->get(['name', 'salary']);

        $html = ReportPrint::html([
            'title' => 'تقرير الإيرادات والذمم',
            'subtitle' => 'حتى '.now()->format('Y-m-d'),
            'ref' => 'REV-'.now()->format('Ymd'),
            'blocks' => [
                [
                    'title' => '١. الإيرادات',
                    'cellRows' => [
                        [['حجوزات مدفوعة', (string) (clone $paid)->count()], ['إيراد الاستشارات', number_format((int) (clone $paid)->sum('total')).' ر.س']],
                        [['فواتير مُصدَرة', number_format($issued).' ر.س'], ['المحصَّل', number_format($collected).' ر.س']],
                        [['الذمم المستحقة', number_format($issued - $collected).' ر.س'], ['إجمالي الرواتب', number_format((int) $staff->sum('salary')).' ر.س']],
                    ],
                ],
                [
                    'title' => '٢. الإيراد حسب القناة',
                    'cellRows' => $byService->map(fn ($r) => [[$r->channel ?: 'أخرى', number_format((int) $r->revenue).' ر.س']])->all(),
                ],
                [
                    'title' => '٣. الرواتب',
                    'cellRows' => $staff->map(fn ($u) => [[$u->name, number_format((int) $u->salary).' ر.س']])->all(),
                ],
            ],
            'footer' => 'النظام الإداري لمكاتب المحاماة — تقرير ماليّ داخلي',
        ]);

        return PdfRenderer::render($html, 'revenue-'.now()->format('Y-m-d').'.pdf');
    }
}
