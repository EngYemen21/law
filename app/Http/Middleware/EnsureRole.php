<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * يسمح بالمرور إذا كان دور المستخدم ضمن الأدوار المطلوبة.
     * الإدارة العليا (بقرار 2026-08-28) مقصورة على مساراتها هي **بلا أي استثناء**:
     * كل ما تحتاجه شاشات الإدارة له نظير admin.* خاص (اعتماد النتيجة، تحويل لاستشارة،
     * الجدولة والفترات، المساعد القانوني، تنزيلات ملف العميل، PDF الفاتورة) —
     * فأي طلب من أدمن لبوابة دور آخر، أيًّا كانت طريقته، يُرفض.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest('/login');
        }

        if ($user->isAdmin()) {
            if (in_array('admin', $roles, true)) {
                return $next($request);
            }

            // مسار من لوحة دور آخر — محظور على الإدارة العليا كليًا
            if (! $request->inertia() && ($request->expectsJson() || $request->ajax())) {
                abort(403, 'لوحات الأدوار الأخرى غير متاحة للإدارة العليا.');
            }

            return redirect('/admin/dashboard')
                ->with('error', 'لوحات الأدوار الأخرى غير متاحة للإدارة العليا.');
        }

        $allowed = array_map(fn (string $r) => Role::from($r), $roles);

        if (in_array($user->role, $allowed, true)) {
            return $next($request);
        }

        // نداءات XHR تُرفض صراحةً — كانت تتبع التحويل فتقرأ 200 وتُظهر الواجهة نجاحاً
        // لعملية لم تُنفَّذ (نفس عطل EnsurePermission المُصلَح هناك). زيارات Inertia مستثناة:
        // تعتمد التحويل مع رسالة الجلسة.
        if (! $request->inertia() && ($request->expectsJson() || $request->ajax())) {
            abort(403, 'لا تملك صلاحية الوصول إلى هذه الصفحة.');
        }

        // إعادة التوجيه إلى لوحة المستخدم مع تنبيه
        return redirect($user->role->home())
            ->with('error', 'لا تملك صلاحية الوصول إلى هذه الصفحة.');
    }
}
