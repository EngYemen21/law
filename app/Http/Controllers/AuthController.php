<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Services\TaqnyatVerifyService;
use App\Support\Audit;
use App\Support\EmailOtpService;
use App\Support\OtpService;
use App\Support\Phone;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

/**
 * المصادقة بلا كلمة مرور — دخول برقم الهويّة + رمز SMS (OTP) عبر واجهة «Verify» الرسميّة من تقنيات،
 * وتسجيل ذاتي للعميل بتحقّق جوال. الدور يُشتقّ حصراً من الخادم (لا يُرسَل من الواجهة).
 * تقنيات تُولّد الرمز وتخزّنه وتتحقّق منه؛ نحفظ معرّف العمليّة (requestId) في الجلسة فقط.
 */
class AuthController extends Controller
{
    // صفحة تسجيل الدخول — تعرض خطوة إدخال الرمز إن كانت هناك عمليّة تحقّق فعّالة في الجلسة
    public function show(Request $request): Response
    {
        // «رجوع/إلغاء» من شاشة الرمز أو الاختيار — يُنهي التدفّق الحالي
        if ($request->boolean('restart')) {
            $request->session()->forget(['otp', 'reg', 'account_choice']);
        }

        $otp = $request->session()->get('otp');
        $choice = $request->session()->get('account_choice');

        return Inertia::render('auth/login', [
            'authState' => $otp ? [
                'step' => 'otp',
                'mode' => $otp['mode'] ?? 'login',
                'channel' => $otp['channel'] ?? 'sms', // sms (تقنيات) | email (Resend)
                // الدخول لا يعرض أرقام الجوال: عرضها يُثبت أنّ للهويّة حساباً مفعّلاً.
                // التسجيل يعرضها لأنّ الجوال أدخله صاحبه بنفسه.
                'maskedTarget' => ($otp['purpose'] ?? 'login') === 'login' ? null : ($otp['masked'] ?? null),
                'resendSeconds' => OtpService::RESEND_SECONDS,
                // يتغيّر مع كل إصدار — لإعادة ضبط مؤقّت الإرسال في الواجهة
                'nonce' => $otp['requestId'] ?? ($otp['expires_at'] ?? null),
            ] : null,
            // مُنتقي الحساب: يظهر حين طابقت الهُويّة عدّة حسابات لنفس الشخص (بعد نجاح الرمز)
            'accountChoice' => $choice
                ? User::whereIn('id', $choice['ids'])->where('status', 'active')->get()
                    ->map(fn (User $u) => ['id' => $u->id, 'roleLabel' => $u->role->label()])
                    ->values()
                : null,
            // تلميح التجاوز التطويريّ المؤقّت (غير الإنتاج فقط) — يُظهر الرمز الثابت للمختبِر
            'devOtp' => app(OtpService::class)->devBypass() ? (string) config('services.auth_dev_otp') : null,
        ]);
    }

    // (1) طلب رمز دخول: التحقّق من الهويّة ثمّ إرسال OTP لجوال الحساب عبر تقنيات
    public function requestOtp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'national_id' => ['required', 'regex:/^\d{10}$/'],
        ], [
            'national_id.required' => 'أدخل رقم الهوية.',
            'national_id.regex' => 'رقم الهوية يجب أن يتكوّن من 10 أرقام.',
        ]);

        $this->ensureConfigured('national_id');

        // **الردّ واحدٌ لكلّ هويّة.** لا حساب، أو موقوف، أو بلا جوال ⇒ شاشة الرمز نفسها بلا إرسال،
        // وأيّ رمزٍ يُرفض برسالة الرمز الخاطئ نفسها. كانت ثلاث رسائل مختلفة («لا يوجد حساب» ·
        // «موقوف» · «لا جوال») تكشف أيّ أرقام الهويّة مسجّلة لدى المكتب ومن منها موقوف.
        $sender = $this->smsSenderFor($data['national_id']);

        if (! $sender) {
            $request->session()->put('otp', $this->decoyOtpSession($data['national_id']));

            return redirect()->route('login');
        }

        // رمز واحد للجوال المشترك — اختيار الحساب يتمّ بعد التحقّق (إن تعدّدت الحسابات)
        $res = app(OtpService::class)->request($sender);

        if (! $res['sent']) {
            throw ValidationException::withMessages(['national_id' => 'تعذّر إرسال رمز التحقّق حالياً، يرجى المحاولة لاحقاً.']);
        }

        $request->session()->put('otp', $this->otpSession($res, 'login', $sender->id, $data['national_id']));

        return redirect()->route('login');
    }

    // (2) تأكيد رمز الدخول عبر تقنيات ثمّ تسجيل الدخول
    public function verifyOtp(Request $request): RedirectResponse
    {
        $data = $this->validateCode($request);
        $otp = $request->session()->get('otp');

        if (! $otp || ($otp['purpose'] ?? null) !== 'login') {
            throw ValidationException::withMessages(['code' => 'انتهت الجلسة، يرجى إعادة إرسال الرمز.']);
        }

        $this->assertSmsCode($request, $otp, $data['code']);

        // حلّ الحسابات المفعّلة التي تُثبت ملكيّةَ **نفس الجوال** الذي وصله الرمز (لا الهُويّة وحدها).
        // أمنيّ: OTP يُثبت ملكيّة جوال واحد؛ فلا يُمنَح الدخول إلا لحسابات ذلك الجوال بالضبط.
        $nid = $otp['national_id'] ?? null;
        $phone = $otp['phone'] ?? null;
        $accounts = ($nid && $phone)
            ? User::where('national_id', $nid)->where('phone', $phone)->get()->filter->isActive()->values()
            : User::whereKey($otp['user_id'] ?? null)->get()->filter->isActive()->values();

        $request->session()->forget('otp');

        if ($accounts->isEmpty()) {
            throw ValidationException::withMessages(['code' => 'تعذّر إتمام الدخول، يرجى المحاولة مجدداً.']);
        }

        // حساب واحد → دخول مباشر؛ أكثر من حساب → مُنتقي الحساب
        if ($accounts->count() === 1) {
            return $this->loginInto($request, $accounts->first());
        }

        $request->session()->put('account_choice', [
            'national_id' => $nid,
            'phone' => $phone,
            'ids' => $accounts->pluck('id')->all(),
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ]);

        return redirect()->route('login');
    }

    // اختيار الحساب بعد نجاح الرمز حين تعدّدت حسابات الهُويّة
    public function chooseAccount(Request $request): RedirectResponse
    {
        $choice = $request->session()->get('account_choice');

        if (! $choice || empty($choice['expires_at']) || Carbon::parse($choice['expires_at'])->isPast()) {
            $request->session()->forget('account_choice');

            throw ValidationException::withMessages(['account_id' => 'انتهت الجلسة، يرجى تسجيل الدخول مجدداً.']);
        }

        $data = $request->validate(['account_id' => ['required', 'integer']]);

        // حارس صارم: الحساب ضمن حسابات الهُويّة **والجوال** المُتحقَّقين والمفعّلة
        $user = User::where('national_id', $choice['national_id'])
            ->where('phone', $choice['phone'])
            ->whereKey($data['account_id'])
            ->where('status', 'active')->first();

        if (! $user || ! in_array($user->id, $choice['ids'], true)) {
            throw ValidationException::withMessages(['account_id' => 'اختيار غير صالح.']);
        }

        $request->session()->forget('account_choice');

        return $this->loginInto($request, $user);
    }

    // تبديل الحساب داخل المنصّة — بين حسابات نفس الشخص فقط (نفس الهُويّة)
    public function switchAccount(Request $request): RedirectResponse
    {
        $data = $request->validate(['account_id' => ['required', 'integer']]);
        $current = $request->user();

        // يشترط هُويّة **وجوال** غير فارغين — التبديل بين حسابات نفس الشخص المُثبت جواله فقط
        abort_if(blank($current->national_id) || blank($current->phone), 403);

        $target = User::where('national_id', $current->national_id)
            ->where('phone', $current->phone)
            ->whereKey($data['account_id'])
            ->where('status', 'active')->first();

        abort_unless($target, 403);

        if ($target->id !== $current->id) {
            Auth::login($target);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $request->session()->forget(['otp', 'reg', 'account_choice']);
            $request->session()->regenerate();
        }

        return redirect($target->role->home());
    }

    /** إتمام الدخول لحساب محدّد (تجديد الجلسة + التوجيه للوحته). */
    private function loginInto(Request $request, User $user): RedirectResponse
    {
        Auth::login($user);
        $request->session()->regenerate();

        Audit::log(
            action: 'تسجيل دخول للنظام',
            description: "سجّل {$user->name} دخولاً ناجحاً بعد التحقق من رمز الجوال.",
            category: 'أمن وحماية',
            auditable: $user,
            auditableRef: $user->email,
            user: $user,
        );

        return redirect()->intended($user->role->home());
    }

    // (3) تسجيل عميل جديد: التحقّق من البيانات ثمّ إرسال OTP لتأكيد الجوال عبر تقنيات
    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^\S+\s+\S+/u'],
            // التفرّد ضمن دور العميل (يسمح بأن يكون للشخص حساب موظف/محامٍ بنفس الهُويّة)
            'national_id' => ['required', 'regex:/^\d{10}$/', Rule::unique('users', 'national_id')->where('role', Role::Client->value)],
            'phone' => ['required', Phone::RULE, Rule::unique('users', 'phone')->where('role', Role::Client->value)],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
        ], [
            'name.required' => 'أدخل الاسم الكامل.',
            'name.regex' => 'أدخل الاسم كاملاً (كلمتان على الأقل).',
            'name.max' => 'الاسم طويل جداً.',
            'national_id.required' => 'أدخل رقم الهوية.',
            'national_id.regex' => 'رقم الهوية يجب أن يتكوّن من 10 أرقام.',
            'national_id.unique' => 'يوجد حساب عميل مسجّل بهذه الهوية، سجّل الدخول بدلاً من ذلك.',
            'phone.required' => 'أدخل رقم الجوال.',
            'phone.regex' => 'رقم الجوال غير صالح — محليّ 05XXXXXXXX أو دوليّ ‎+9665XXXXXXXX.',
            'phone.unique' => 'يوجد حساب مسجّل بهذا الجوال، سجّل الدخول بدلاً من ذلك.',
            'email.required' => 'أدخل البريد الإلكتروني.',
            'email.email' => 'أدخل بريداً إلكترونياً صحيحاً.',
            'email.max' => 'البريد الإلكتروني طويل جداً.',
            'email.unique' => 'يوجد حساب مسجّل بهذا البريد الإلكتروني.',
        ]);

        // دفاع عميق: امنع التسجيل الذاتي بهُويّة/جوال يخصّان حساب موظف/محامٍ/إدارة
        // (الإضافة الحقيقيّة للأدوار تكون عبر الإدارة فقط) — يسدّ انتحال هُويّة الطاقم.
        $staffExists = User::where(fn ($q) => $q->where('national_id', $data['national_id'])->orWhere('phone', $data['phone']))
            ->whereIn('role', [Role::Employee->value, Role::Lawyer->value, Role::Admin->value])
            ->exists();

        if ($staffExists) {
            throw ValidationException::withMessages(['national_id' => 'هذا الرقم مسجّل لدى المكتب، يرجى مراجعة الإدارة.']);
        }

        $this->ensureConfigured('phone');

        $res = app(OtpService::class)->requestForRegistration($data['phone']);

        if (! $res['sent']) {
            throw ValidationException::withMessages(['phone' => 'تعذّر إرسال رمز التحقّق حالياً، يرجى المحاولة لاحقاً.']);
        }

        $request->session()->put('reg', $data + ['sms_issues' => 1]);
        $request->session()->put('otp', $this->otpSession($res, 'register'));

        return redirect()->route('login');
    }

    // (4أ) تأكيد جوال التسجيل (1 من 2) عبر تقنيات ثمّ إرسال رمز البريد (Resend)
    public function verifyRegisterPhone(Request $request): RedirectResponse
    {
        $data = $this->validateCode($request);
        $otp = $request->session()->get('otp');
        $reg = $request->session()->get('reg');

        if (! $otp || ($otp['purpose'] ?? null) !== 'register' || ($otp['channel'] ?? null) !== 'sms' || ! $reg) {
            throw ValidationException::withMessages(['code' => 'انتهت الجلسة، يرجى إعادة التسجيل.']);
        }

        $this->assertSmsCode($request, $otp, $data['code']);

        // الجوال مؤكَّد → أرسل رمز البريد (الخطوة 2 من 2)
        $email = app(EmailOtpService::class)->issue($reg['email'], $reg['name']);
        $reg['email_issues'] = 1; // عدّاد إصدارات ثابت (لا يُصفَّر بإعادة الإرسال)
        $request->session()->put('reg', $reg);
        $request->session()->put('otp', $email + ['purpose' => 'register', 'mode' => 'register', 'phone_verified' => true]);

        return redirect()->route('login');
    }

    // (4ب) تأكيد بريد التسجيل (2 من 2) عبر Resend ثمّ إنشاء الحساب والدخول
    public function verifyRegisterEmail(Request $request): RedirectResponse
    {
        $data = $this->validateCode($request);
        $otp = $request->session()->get('otp');
        $reg = $request->session()->get('reg');

        if (! $otp || ($otp['purpose'] ?? null) !== 'register' || ($otp['channel'] ?? null) !== 'email' || ! $reg) {
            throw ValidationException::withMessages(['code' => 'انتهت الجلسة، يرجى إعادة التسجيل.']);
        }

        if (! app(EmailOtpService::class)->verify($otp, $data['code'])) {
            $otp['attempts'] = ($otp['attempts'] ?? 0) + 1; // حدّ المحاولات
            $request->session()->put('otp', $otp);

            throw ValidationException::withMessages(['code' => 'رمز التحقّق غير صحيح أو منتهٍ.']);
        }

        try {
            $user = User::create([
                'name' => $reg['name'],
                'email' => $reg['email'],
                'national_id' => $reg['national_id'],
                'phone' => $reg['phone'],
                'role' => Role::Client,
                'status' => 'active',
                'avatar_initials' => $this->initials($reg['name']),
                'phone_verified_at' => now(),
                'email_verified_at' => now(),
                // كلمة مرور عشوائيّة مُجزّأة لتلبية العمود — الدخول بالـOTP لا بها
                'password' => Hash::make(Str::password(32)),
            ]);
        } catch (QueryException) {
            // سباق نادر: طلب متزامن سبق بنفس الهوية/الجوال/البريد → رسالة عربيّة بدل خطأ 500
            $request->session()->forget(['otp', 'reg']);

            throw ValidationException::withMessages(['code' => 'يوجد حساب مسجّل بهذه البيانات، يرجى تسجيل الدخول.']);
        }

        $request->session()->forget(['otp', 'reg']);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended($user->role->home());
    }

    // إعادة إرسال الرمز — يبدأ عمليّة تحقّق جديدة من بيانات الجلسة (حسب الغرض والقناة)
    public function resend(Request $request): RedirectResponse
    {
        $otp = $request->session()->get('otp');

        if (! $otp) {
            return redirect()->route('login');
        }

        // دخول: إعادة رمز SMS للجوال المشترك (بالهُويّة) — بسقف إصدارات، والهويّة غير
        // المسجّلة تُعامَل بالمسار نفسه تماماً (السقف نفسه، بلا إرسال) كي لا تكشف الإعادةُ شيئاً
        if (($otp['purpose'] ?? null) === 'login') {
            $issues = $this->nextSmsIssue((int) ($otp['issues'] ?? 1));
            $nid = (string) ($otp['national_id'] ?? '');
            $sender = $nid !== '' ? $this->smsSenderFor($nid) : null;

            $request->session()->put('otp', $sender
                ? $this->otpSession(app(OtpService::class)->request($sender), 'login', $sender->id, $nid, $issues)
                : $this->decoyOtpSession($nid, $issues));

            return redirect()->route('login');
        }

        // تسجيل: إعادة حسب القناة الحاليّة (جوال/بريد)
        if (($otp['purpose'] ?? null) === 'register' && ($reg = $request->session()->get('reg'))) {
            if (($otp['channel'] ?? 'sms') === 'email') {
                // حدّ إصدارات رمز البريد (منع تصفير عدّاد المحاولات بإعادة الإرسال)
                $issues = ($reg['email_issues'] ?? 1) + 1;
                if ($issues > EmailOtpService::MAX_ISSUES) {
                    throw ValidationException::withMessages(['code' => 'تجاوزت حدّ إعادة إرسال رمز البريد، يرجى إعادة التسجيل.']);
                }
                $reg['email_issues'] = $issues;
                $request->session()->put('reg', $reg);

                $email = app(EmailOtpService::class)->issue($reg['email'], $reg['name']);
                $request->session()->put('otp', $email + ['purpose' => 'register', 'mode' => 'register', 'phone_verified' => true]);
            } else {
                // سقف إصدارات رمز الجوال (نظير رمز البريد أعلاه)
                $reg['sms_issues'] = $this->nextSmsIssue((int) ($reg['sms_issues'] ?? 1));
                $request->session()->put('reg', $reg);

                $request->session()->put('otp', $this->otpSession(app(OtpService::class)->requestForRegistration($reg['phone']), 'register'));
            }
        }

        return redirect()->route('login');
    }

    // تسجيل الخروج
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /** التحقّق من صيغة الرمز (4 أرقام). */
    private function validateCode(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'regex:/^\d{4}$/'],
        ], [
            'code.required' => 'أدخل رمز التحقّق.',
            'code.regex' => 'رمز التحقّق يتكوّن من 4 أرقام.',
        ]);
    }

    /** يمنع بدء أيّ تدفّق إن لم تُهيّأ مفاتيح تقنيات (لا محاكاة — نمط بوّابة الدفع). */
    private function ensureConfigured(string $field): void
    {
        // التجاوز التطويريّ المؤقّت يُغني عن تهيئة المزوّد (غير الإنتاج فقط)
        if (app(OtpService::class)->devBypass()) {
            return;
        }

        if (! app(TaqnyatVerifyService::class)->isConfigured()) {
            throw ValidationException::withMessages([$field => 'خدمة التحقّق غير مهيّأة حالياً، يرجى مراجعة الإدارة.']);
        }
    }

    /** بناء بنية جلسة عمليّة تحقّق SMS (تقنيات) الموحّدة. */
    /**
     * يتحقّق من رمز SMS ويحرس عدد المحاولات على حمولة الجلسة نفسها.
     *
     * كان الفشل لا يُحصى ولا يُبطل requestId، فيُعاد استعمال نفس العمليّة بلا نهاية —
     * ورمز من أربعة أرقام مساحته 10000 احتمال. حدّ المعدّل وحده لا يكفي: هو زمنيّ
     * ويُستأنف بعد دقيقة. نظير MAX_ATTEMPTS في مسار البريد (EmailOtpService).
     */
    private function assertSmsCode(Request $request, array $otp, string $code): void
    {
        // الحمولة تُعطَّل ولا تُحذف: حذفها يُفقد مرساةَ حدّ المعدّل (requestId) فيُعاد
        // للمهاجم دلوٌ جديد، ويصير إبطال الرمز طريقاً لتجديد الحصّة بدل تضييقها.
        if ((int) ($otp['attempts'] ?? 0) >= OtpService::MAX_ATTEMPTS) {
            throw ValidationException::withMessages([
                'code' => 'تجاوزت عدد المحاولات المسموح — اطلب رمزاً جديداً.',
            ]);
        }

        // عمليّة هويّةٍ بلا حسابٍ صالح (decoy) لا تنجح أبداً — وتمرّ بعدّاد المحاولات والرسالة نفسيهما
        if (empty($otp['decoy']) && app(OtpService::class)->verify($otp['requestId'], (string) $otp['phone'], $code)) {
            return;
        }

        $otp['attempts'] = (int) ($otp['attempts'] ?? 0) + 1;
        $request->session()->put('otp', $otp);

        Audit::log(
            action: 'محاولة دخول فاشلة',
            description: 'رمز تحقق خاطئ للجوال '.($otp['masked'] ?? '—').' (المحاولة '.$otp['attempts'].' من '.OtpService::MAX_ATTEMPTS.').',
            category: 'أمن وحماية',
            severity: 'warning',
        );

        throw ValidationException::withMessages(['code' => 'رمز التحقّق غير صحيح أو منتهٍ.']);
    }

    /**
     * @param  array<string, mixed>  $res
     * @return array<string, mixed>
     */
    private function otpSession(array $res, string $purpose, ?int $userId = null, ?string $nationalId = null, int $issues = 1): array
    {
        return [
            'channel' => 'sms',
            'requestId' => $res['request_id'],
            'phone' => $res['phone'],
            'purpose' => $purpose,
            'user_id' => $userId,
            'national_id' => $nationalId,
            'masked' => $res['masked_phone'],
            'mode' => $purpose,
            'attempts' => 0,
            'issues' => $issues,
        ];
    }

    /** الحساب المفعّل ذو الجوال لهذه الهويّة (مُرسِل الرمز) — أو null: لا حساب أو موقوف أو بلا جوال. */
    private function smsSenderFor(string $nationalId): ?User
    {
        return User::where('national_id', $nationalId)->get()
            ->filter->isActive()
            ->first(fn (User $u) => filled($u->phone));
    }

    /**
     * عمليّة دخولٍ شكليّة لهويّةٍ بلا حسابٍ صالح: البنية نفسها بلا إرسال ولا جوال، ولا تنجح أبداً.
     * `decoy` لا يغادر الخادم (الجلسة لا تُرسَل للواجهة إلا عبر show()، وهي لا تقرؤه).
     *
     * @return array<string, mixed>
     */
    private function decoyOtpSession(string $nationalId, int $issues = 1): array
    {
        return [
            'channel' => 'sms',
            'requestId' => 'none-'.Str::uuid(),
            'phone' => null,
            'purpose' => 'login',
            'user_id' => null,
            'national_id' => $nationalId,
            'masked' => null,
            'mode' => 'login',
            'attempts' => 0,
            'issues' => $issues,
            'decoy' => true,
        ];
    }

    /** رقم الإصدار التالي لرمز الجوال — أو رفضٌ عند تجاوز OtpService::MAX_ISSUES. */
    private function nextSmsIssue(int $current): int
    {
        $next = $current + 1;

        if ($next > OtpService::MAX_ISSUES) {
            throw ValidationException::withMessages([
                'code' => 'تجاوزت حدّ إعادة إرسال الرمز — ابدأ من جديد بعد قليل.',
            ]);
        }

        return $next;
    }

    /** الأحرف الأولى من أوّل كلمتين للأفاتار (مثل «ع ع»). */
    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return mb_substr($parts[0] ?? '', 0, 1).' '.mb_substr($parts[1] ?? '', 0, 1);
    }
}
