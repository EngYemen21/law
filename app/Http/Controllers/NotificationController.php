<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * الشاشة المرتبطة بكل «موضوع» إشعار حسب دور المشاهِد — كلّ دور يُفتح على شاشته الصحيحة.
     * موضوعٌ لا شاشة له لدى الدور (مثل الفواتير للموظف) يُترك null فلا يكون الإشعار قابلاً للنقر.
     */
    private const ROUTES = [
        'client' => [
            'tickets' => '/tickets', 'cases' => '/cases', 'execs' => '/execs',
            'consults' => '/myconsults', 'meetreqs' => '/meetreqs',
            'appointments' => '/appointments', 'invoices' => '/invoices', 'documents' => '/documents',
        ],
        'employee' => [
            'tickets' => '/employee/tickets', 'cases' => '/employee/cases', 'execs' => '/employee/execs',
            'consults' => '/employee/consults', 'meetreqs' => '/employee/meetreqs',
        ],
        'lawyer' => [
            'tickets' => '/lawyer/tickets', 'cases' => '/lawyer/cases', 'execs' => '/lawyer/execs',
            'consults' => '/lawyer/consultrecv', 'meetreqs' => '/lawyer/meetreqs',
            'appointments' => '/lawyer/calendar',
        ],
        'admin' => [
            'tickets' => '/admin/tickets', 'cases' => '/admin/cases', 'execs' => '/admin/execs',
            'consults' => '/admin/consults', 'meetreqs' => '/admin/meetreqs',
            'invoices' => '/admin/finance?tab=invoices',
        ],
    ];

    // قائمة إشعارات المستخدم الحالي (مع رابط الشاشة المرتبطة بحسب دوره لجعلها قابلة للنقر)
    public function index(Request $request): Response
    {
        $role = $request->user()->role->value;

        $notifications = UserNotification::where('user_id', $request->user()->id)->latest('id')->get()
            ->map(fn (UserNotification $n) => array_merge($n->toData(), [
                'link' => self::linkFor($role, $n->body, $n->icon),
            ]));

        return Inertia::render('notifications', [
            'notifications' => $notifications,
        ]);
    }

    // تعليم كل الإشعارات كمقروءة
    public function markAllRead(Request $request): RedirectResponse
    {
        UserNotification::where('user_id', $request->user()->id)->update(['is_read' => true]);

        return back();
    }

    /** رابط الشاشة المرتبطة بالإشعار لدور المشاهِد، أو null إن لم توجد شاشة مناسبة. */
    private static function linkFor(string $role, ?string $body, ?string $icon): ?string
    {
        $concept = self::conceptOf($body, $icon);

        return $concept !== null ? (self::ROUTES[$role][$concept] ?? null) : null;
    }

    /**
     * يستخرج «موضوع» الإشعار من نصّه/أيقونته (مصدر موحّد لكل الأدوار).
     * الأولوية للموضوع (تذكرة/قضية/استشارة…) قبل كلمات الدفع العامّة، فإشعار «دفع استشارة»
     * يُفتح على الاستشارات لا الفواتير.
     */
    private static function conceptOf(?string $body, ?string $icon): ?string
    {
        $t = trim(strip_tags($body ?? ''));

        return match (true) {
            (bool) preg_match('/تذكرة|SB-\d/u', $t) => 'tickets',
            // التنفيذ قبل القضايا: «أتعاب التنفيذ» و«فاتورة أتعاب التنفيذ» كانت تفتح صفحة القضايا
            // لمجرّد ورود كلمة «أتعاب» فيها. و`EX-` لم يكن يطابق أرقامنا الحقيقيّة (EXE-…).
            (bool) preg_match('/تنفيذ|EXE?-\d/u', $t) => 'execs',
            (bool) preg_match('/قضية|CASE-|أتعاب/u', $t) => 'cases',
            (bool) preg_match('/استشار|CN-\d|تسعير/u', $t) => 'consults',
            (bool) preg_match('/اجتماع|MR-\d/u', $t) => 'meetreqs',
            (bool) preg_match('/موعد|حجز/u', $t) => 'appointments',
            (bool) preg_match('/فاتورة|INV-/u', $t) => 'invoices',
            (bool) preg_match('/مستند|رفع/u', $t) => 'documents',
            // احتياط حسب الأيقونة
            $icon === 'cal' => 'appointments',
            $icon === 'card' => 'invoices',
            $icon === 'video' => 'meetreqs',
            default => null,
        };
    }
}
