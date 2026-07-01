<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * يسمح بالمرور إذا كان دور المستخدم ضمن الأدوار المطلوبة،
     * أو إذا كان مديراً (الإدارة تطّلع على كل اللوحات).
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest('/login');
        }

        // الإدارة لها صلاحية الوصول لكل الأدوار (إشراف)
        if ($user->isAdmin()) {
            return $next($request);
        }

        $allowed = array_map(fn (string $r) => Role::from($r), $roles);

        if (in_array($user->role, $allowed, true)) {
            return $next($request);
        }

        // إعادة التوجيه إلى لوحة المستخدم مع تنبيه
        return redirect($user->role->home())
            ->with('error', 'لا تملك صلاحية الوصول إلى هذه الصفحة.');
    }
}
