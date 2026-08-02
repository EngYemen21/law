<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * حساب المستخدم — تحديث البيانات الشخصية وتغيير كلمة المرور (متاح لأي مستخدم مسجّل).
 */
class ProfileController extends Controller
{
    // حفظ البيانات الشخصية للمستخدم الحالي
    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($request->user()->id)],
        ]);

        $request->user()->update($data);

        return back()->with('success', 'تم حفظ بياناتك.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);

        return back()->with('success', 'تم تغيير كلمة المرور بنجاح.');
    }
}
