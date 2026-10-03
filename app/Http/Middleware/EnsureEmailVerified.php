<?php

namespace App\Http\Middleware;

use App\Support\EmailVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * **العميل لا يستعمل حسابه قبل تأكيد بريده** (قرار المالك 2026-10-03، إلزاميّ) — كلّ طلبٍ منه يُوجَّه إلى
 * صفحة «أكّد بريدك»، إلّا الصفحة نفسها والخروج وتبديل الحساب. الفواتير وتأكيد المواعيد والتذكير تصل بالبريد،
 * فبريدٌ خطأ يعني عميلاً لا يستلمها. الطاقم لا يمرّ هنا: يُنشأ بريده مؤكَّداً من شاشة الموظّفين.
 */
class EnsureEmailVerified
{
    /** المسارات المسموحة قبل التأكيد. */
    private const ALLOWED = ['email.verify', 'email.verify.send', 'email.verify.confirm', 'email.verify.change', 'logout', 'auth.switch-account'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! EmailVerification::required($request->user()) || in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return $next($request);
        }

        // طلبات البيانات (fetch/axios بلا Inertia) لا تُحوَّل — تُرفض برسالةٍ تقرؤها الواجهة
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'أكّد بريدك الإلكتروني أولاً.'], 409);
        }

        return redirect()->route('email.verify');
    }
}
