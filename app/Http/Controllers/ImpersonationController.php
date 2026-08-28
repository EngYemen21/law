<?php

namespace App\Http\Controllers;

/**
 * أُلغيت ميزة «معاينة لوحة الموظف» (الإمبرسنيشن) بقرار المستخدم 2026-08-28:
 * الإدارة العليا مقصورة على لوحتها ولا تدخل بحساب أي دور آخر.
 * الملف يبقى موثِّقًا (الكود الميت يُعلَّق لا يُحذف)، ومساره معلَّق في routes/web.php،
 * وزر البدء معلَّق في StaffController::preview وadmin/staff.tsx.
 */
class ImpersonationController extends Controller
{
    // public function leave(Request $request): RedirectResponse
    // {
    //     // لا يُسمح إلا أثناء معاينة فعلية بدأها مدير
    //     abort_unless($request->session()->has('impersonator_id'), 403);
    //
    //     $adminId = $request->session()->pull('impersonator_id');
    //     $admin = User::find($adminId);
    //
    //     if (! $admin || ! $admin->isAdmin()) {
    //         Auth::logout();
    //
    //         return redirect('/login');
    //     }
    //
    //     $impersonatedId = $request->user()?->id;
    //     Auth::login($admin);
    //     $request->session()->regenerate(); // منع session fixation
    //     Log::info('impersonation.end', ['admin_id' => $admin->id, 'target_id' => $impersonatedId]);
    //
    //     return redirect('/admin/staff');
    // }
}
