<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * إيقاف فوري: يُخرج أي مستخدم موقوف (status=suspended) عند أول طلب،
 * فلا يستمر بجلسة مفتوحة بعد إيقافه.
 */
class EnsureActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->with('error', 'الحساب موقوف حالياً، يرجى مراجعة الإدارة.');
        }

        return $next($request);
    }
}
