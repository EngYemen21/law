<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\OtpService;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

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

        // ── الجوال لا يُكتب إلا بعد تأكيد رمز يصل إلى **الرقم الجديد** ──
        // كان يُحفَظ فوراً بلا أي تحقّق، والجوال هو عامل المصادقة الوحيد (الدخول برمز OTP)
        // وقناة تذكيرات SMS معاً: فمن يجلس أمام جلسة مفتوحة دقيقةً واحدة يغيّر الرقم
        // ويستولي على الحساب نهائياً — صاحبه لا يستطيع حتى تسجيل الدخول ليستعيده.
        $newPhone = $data['phone'] ?? null;
        unset($data['phone']); // يُستثنى من الحفظ المباشر مهما كان
        $phonePending = false;

        // بقيّة الحقول تُحفظ **أوّلاً**: فشل إرسال الرمز (تعذّر المزوّد) كان يرتدّ قبل
        // الحفظ فيُضيّع تعديل الاسم والبريد معاً — عقوبة على المستخدم لعطل خارجيّ.
        $user->update($data);

        if ($newPhone !== null && Phone::intl($newPhone) !== Phone::intl((string) $user->phone)) {
            $res = app(OtpService::class)->requestForRegistration(Phone::intl($newPhone));

            if (! ($res['sent'] ?? false)) {
                return back()->withErrors(['phone' => 'تعذّر إرسال رمز التحقّق للرقم الجديد، حاول لاحقاً.']);
            }

            // الرقم الجديد في الجلسة لا في القاعدة — الحساب يبقى قابلاً للدخول بالرقم القديم
            $request->session()->put('phone_change', [
                'phone' => Phone::intl($newPhone),
                'request_id' => $res['request_id'],
                'user_id' => $user->id,
                // صلاحيّة رمز التحقّق الواحدة (`otp_ttl_minutes`)
                'expires_at' => now()->addMinutes(OtpService::ttlMinutes())->timestamp,
            ]);
            $phonePending = true;
        }

        return $phonePending
            ? back()->with('success', 'حُفظت بياناتك. أُرسل رمز تحقّق إلى الرقم الجديد — أكّده لإتمام تغيير الجوال.')
            : back()->with('success', 'تم حفظ بياناتك.');
    }

    /**
     * تأكيد الجوال الجديد — هنا وحده يُكتب الرقم.
     * ملكية الجلسة مفحوصة صراحةً (user_id) فلا يُستعمل طلب معلّق لحساب آخر بعد تبديل الحساب.
     */
    public function verifyPhoneChange(Request $request): RedirectResponse
    {
        $user = $request->user();
        $pending = $request->session()->get('phone_change');

        if (! is_array($pending) || (int) ($pending['user_id'] ?? 0) !== $user->id) {
            throw ValidationException::withMessages(['code' => 'لا يوجد طلب تغيير جوال معلّق.']);
        }

        if (now()->timestamp > (int) ($pending['expires_at'] ?? 0)) {
            $request->session()->forget('phone_change');
            throw ValidationException::withMessages(['code' => 'انتهت صلاحية الرمز، أعِد المحاولة.']);
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        if (! app(OtpService::class)->verify($pending['request_id'], $pending['phone'], $data['code'])) {
            throw ValidationException::withMessages(['code' => 'الرمز غير صحيح.']);
        }

        // إعادة فحص التفرّد عند الكتابة لا عند الطلب: قد يُسجَّل الرقم لغيره خلال مهلة الرمز
        $taken = User::where('phone', $pending['phone'])->where('role', $user->role->value)
            ->where('id', '!=', $user->id)->exists();
        if ($taken) {
            $request->session()->forget('phone_change');
            throw ValidationException::withMessages(['code' => 'هذا الجوال مستخدم في حساب آخر بنفس الدور.']);
        }

        $user->update(['phone' => $pending['phone']]);
        $request->session()->forget('phone_change');

        return back()->with('success', 'تم تأكيد الجوال الجديد.');
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
