<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use App\Support\ClientAccount;
use App\Support\EmailVerification;
use App\Support\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * **صفحة «أكّد بريدك»** — يبلغها العميل غير المؤكَّد بريدُه بعد دخوله (`EnsureEmailVerified`)، ولا يستعمل
 * حسابه حتى يؤكّده (قرار المالك 2026-10-03: إلزاميّ). فيها: إرسال الرمز، وإدخاله، وتصحيح البريد قبل تأكيده.
 */
class EmailVerificationController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (! EmailVerification::required($user)) {
            return redirect($user->role->home());
        }

        return Inertia::render('auth/verify-email', [
            'email' => $user->email,
            // لحظة إصدار الرمز المعلّق (أو null) — تتغيّر مع كلّ إرسال فتُعيد الواجهة خانات الرمز ومؤقّته
            'sentAt' => EmailVerification::pending($user)['sent_at'] ?? null,
            'resendSeconds' => EmailVerification::resendIn($user),
            'devOtp' => app(OtpService::class)->devBypass() ? (string) config('services.auth_dev_otp') : null,
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $this->unverified($request);
        $result = EmailVerification::send($user);

        if (! $result['sent']) {
            throw ValidationException::withMessages(['email' => $result['error']]);
        }

        return back()->with('success', 'أرسلنا رمز التأكيد إلى بريدك.');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $this->unverified($request);
        $data = $request->validate(['code' => ['required', 'regex:/^\d{4}$/']], [
            'code.required' => 'أدخل رمز التحقّق.',
            'code.regex' => 'رمز التحقّق يتكوّن من 4 أرقام.',
        ]);

        if (! EmailVerification::confirm($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'رمز التحقّق غير صحيح أو منتهٍ — أعد إرسال رمزٍ جديد إن انتهى.']);
        }

        Audit::log(
            action: 'تأكيد البريد الإلكتروني',
            description: "أكّد {$user->name} بريده الإلكتروني برمز التحقّق.",
            category: ClientAccount::AUDIT_CATEGORY,
            auditable: $user,
            auditableRef: $user->email,
            user: $user,
        );

        return redirect($user->role->home())->with('success', 'تم تأكيد بريدك الإلكتروني.');
    }

    /** تصحيح البريد قبل تأكيده — ثمّ يصل الرمز إلى البريد الجديد. */
    public function change(Request $request): RedirectResponse
    {
        $user = $this->unverified($request);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], ClientAccount::messages());

        $old = (string) $user->email;
        EmailVerification::changeEmail($user, trim($data['email']));

        Audit::log(
            action: 'تصحيح البريد الإلكتروني قبل تأكيده',
            description: "صحّح {$user->name} بريده الإلكتروني قبل تأكيده.",
            category: ClientAccount::AUDIT_CATEGORY,
            auditable: $user,
            auditableRef: $user->email,
            beforeState: ['البريد' => $old],
            afterState: ['البريد' => $user->email],
            user: $user,
        );

        return $this->send($request);
    }

    /** الإجراءات لصاحب بريدٍ غير مؤكَّد وحده — المؤكَّد لا يُعاد تأكيده ولا يُغيَّر بريده من هنا. */
    private function unverified(Request $request): User
    {
        $user = $request->user();
        abort_unless(EmailVerification::required($user), 403);

        return $user;
    }
}
