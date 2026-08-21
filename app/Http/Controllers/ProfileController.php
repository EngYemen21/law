<?php

namespace App\Http\Controllers;

use App\Support\Phone;
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
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // الجوال هو عامل المصادقة الوحيد (الدخول برمز OTP): يُتحقّق شكله، ويحترم القيد
            // المركّب (phone, role) وإلا سقط الحفظ باستثناء قاعدة بيانات غير مُلتقَط (صفحة 500).
            'phone' => [
                'nullable', 'string', 'max:30', Phone::RULE,
                Rule::unique('users', 'phone')->where('role', $user->role->value)->ignore($user->id),
            ],
            'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($user->id)],
        ], [
            'phone.regex' => 'رقم الجوال غير صالح.',
            'phone.unique' => 'هذا الجوال مستخدم في حساب آخر بنفس الدور.',
        ]);

        $user->update($data);

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
