<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    // قائمة إشعارات العميل الحالي (مع رابط الشاشة المرتبطة لجعلها قابلة للنقر)
    public function index(Request $request): Response
    {
        $notifications = UserNotification::where('user_id', $request->user()->id)->latest('id')->get()
            ->map(fn (UserNotification $n) => array_merge($n->toData(), [
                'link' => self::clientLink($n->body, $n->icon),
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

    /**
     * اشتقاق الشاشة المرتبطة بإشعار العميل من نصّه (يطابق notifTarget لدور العميل في التصميم).
     * يُرجع مسار العميل أو null إن لم توجد شاشة مرتبطة.
     */
    private static function clientLink(?string $body, ?string $icon): ?string
    {
        $t = trim(strip_tags($body ?? ''));

        return match (true) {
            (bool) preg_match('/تذكرة|SB-\d/u', $t) => '/tickets',
            (bool) preg_match('/فاتورة|INV-|سداد|دفع/u', $t) => '/invoices',
            (bool) preg_match('/موعد|حجز/u', $t) => '/appointments',
            (bool) preg_match('/استشار|CN-\d|تسعير|أتعاب/u', $t) => '/myconsults',
            (bool) preg_match('/اجتماع|MR-\d/u', $t) => '/meetreqs',
            (bool) preg_match('/تنفيذ|EX-\d/u', $t) => '/execs',
            (bool) preg_match('/قضية|CASE-/u', $t) => '/cases',
            (bool) preg_match('/مستند|رفع/u', $t) => '/documents',
            // احتياط حسب الأيقونة (كما في التصميم)
            $icon === 'cal' => '/appointments',
            $icon === 'card' => '/invoices',
            $icon === 'video' => '/meetreqs',
            default => null,
        };
    }
}
