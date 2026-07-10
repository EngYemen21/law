<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * إنهاء معاينة لوحة الموظف — يعيد الدخول للمُدير الأصلي المحفوظ في الجلسة.
 * محصور بوجود جلسة معاينة فعلية (impersonator_id).
 */
class ImpersonationController extends Controller
{
    public function leave(Request $request): RedirectResponse
    {
        // لا يُسمح إلا أثناء معاينة فعلية بدأها مدير
        abort_unless($request->session()->has('impersonator_id'), 403);

        $adminId = $request->session()->pull('impersonator_id');
        $admin = User::find($adminId);

        if (! $admin || ! $admin->isAdmin()) {
            Auth::logout();

            return redirect('/login');
        }

        $impersonatedId = $request->user()?->id;
        Auth::login($admin);
        $request->session()->regenerate(); // منع session fixation
        Log::info('impersonation.end', ['admin_id' => $admin->id, 'target_id' => $impersonatedId]);

        return redirect('/admin/staff');
    }
}
