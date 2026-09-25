<?php

namespace App\Providers;

use App\Models\LegalCatalogueAlias;
use App\Models\LegalDepartment;
use App\Models\LegalDocument;
use App\Models\LegalService;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Support\LegalCatalogue;
use App\Support\OtpService;
use App\Support\Phone;
use App\Support\SenderIp;
use App\Support\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // نسخة واحدة للبوّابة داخل الطلب: غلاف المزوّد يُبلّغها بالاستهلاك عبر
        // `recordUsage` وهي تقرؤه فور عودة النداء. بلا الربط تُنشأ نسخة لكل
        // `app()` فيضيع الرقم بين نسختين مختلفتين.
        $this->app->singleton(AiGateway::class);

        $tmpDir = storage_path('app/browsershot-tmp');
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0777, true);
        }
        putenv("TMP={$tmpDir}");
        putenv("TEMP={$tmpDir}");
        putenv("TMPDIR={$tmpDir}");
        $_ENV['TMP'] = $_ENV['TEMP'] = $_ENV['TMPDIR'] = $tmpDir;
        $_SERVER['TMP'] = $_SERVER['TEMP'] = $_SERVER['TMPDIR'] = $tmpDir;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // ربط {doc} بنموذج LegalDocument صراحةً — لتفادي أي تعارض مع Document الحالي
        Route::model('doc', LegalDocument::class);

        // عنوان IP لمُرسِل الرسالة: ما يُكتب داخل مهمّة طابور — ولو متزامنةً في طلب مستخدم — لا مُرسِلَ
        // بشريّاً له، فلا يُنسب لصاحب الطلب. التتبّع هنا والقاعدة في `SenderIp`.
        SenderIp::trackJobs();

        // تجاوز OTP التطويري (رمز ثابت لأي هوية) للاختبار
        // if (OtpService::isDevOtpConfigured() && ! app(OtpService::class)->devBypass()) {
        //     throw new \RuntimeException('AUTH_DEV_OTP مضبوط خارج بيئة التطوير — أزِله فوراً من ملف البيئة.');
        // }

        // الإدارة العليا (enum Admin) تتجاوز كل الصلاحيات — يجعل $user->can(...) صحيحاً دائماً لها
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        // **الكتابة تُبطل القراءة المحفوظة.** `SettingsRegistry` يقرأ مفاتيحه باستعلامٍ واحد
        // يُحفظ لعمر الطلب؛ فمن يكتب إعداداً بعد قراءته — الشاشة أو أمرٌ أو اختبار — يجب أن
        // يرى ما كتب لا ما قرأه قبل سطرٍ. الربط هنا لا في `Setting` كي يبقى النموذج غُفلاً عن غلافه.
        Setting::saved(fn () => SettingsRegistry::flush());
        Setting::deleted(fn () => SettingsRegistry::flush());

        // الكتالوج القانونيّ على النمط نفسه: أيّ حفظٍ في أقسامه أو خدماته أو أسمائه البديلة
        // يُنسي لقطة الطلب، فتقرأ الشاشة والإسناد ما كُتب للتوّ.
        foreach ([LegalDepartment::class, LegalService::class, LegalCatalogueAlias::class] as $model) {
            $model::saved(fn () => LegalCatalogue::flush());
            $model::deleted(fn () => LegalCatalogue::flush());
        }

        // حدّ طلب رمز التحقّق (منع قصف الجوال وتخمين الرمز): المرساة الجلسة والهويّة والجوال — **لا الـIP**.
        // وكان `trustProxies(at:'*')` يجعل $request->ip() قيمةً يرسلها الطرف الآخر في X-Forwarded-For،
        // فكان تدويرها يمنح حصّةً جديدة لكل طلب. (الثقة صارت للخادم نفسه وحده — والمرساة تبقى
        // بعيدةً عن الـIP: دفاعٌ لا يتّكئ على إعدادٍ قد يتغيّر.) والجلسة وحدها لا تكفي: من يبدأ من جديد يأخذ جلسةً
        // جديدة — لذا حدٌّ بالدقيقة وبالساعة لكلّ هويّة ولكلّ جوالٍ يُرسَل إليه، أيّاً كانت الجلسة.
        RateLimiter::for('otp-request', function (Request $request) {
            $session = $request->session();
            $text = fn ($value) => is_string($value) ? trim($value) : '';

            $nid = $text($request->input('national_id')) ?: $text($session->get('otp.national_id')) ?: $text($session->get('reg.national_id'));
            $phone = $text($request->input('phone')) ?: $text($session->get('reg.phone'));

            $limits = [Limit::perMinute(5)->by('otp-request|session|'.$session->getId())];
            foreach (['nid' => $nid, 'phone' => $phone === '' ? '' : Phone::intl($phone)] as $kind => $value) {
                if ($value !== '') {
                    $limits[] = Limit::perMinute(3)->by("otp-request|{$kind}|minute|{$value}");
                    $limits[] = Limit::perHour(10)->by("otp-request|{$kind}|hour|{$value}");
                }
            }

            return $limits;
        });

        // حدّ تأكيد الرمز/التبديل (منع التخمين): 5/دقيقة.
        // المرساة الجلسة أولاً لا الـIP: مع trustProxies(at:'*') يصير $request->ip() قيمةً
        // يرسلها العميل في X-Forwarded-For، فكان تدوير الترويسة يمنح حصّة جديدة كل مرة —
        // أي تخمين بلا حدّ لرمز من أربعة أرقام. معرّف الجلسة موقَّع بكوكي فلا يُزوَّر،
        // ومن يدوّره يفقد session('otp') فلا يبقى ما يتحقّق منه. يُضاف الـIP للتفريق بين
        // المهاجمين على جلسات مختلفة.
        RateLimiter::for('otp-verify', function (Request $request) {
            // الترتيب: المستخدم المسجَّل (switch-account) ← معرّف عمليّة الرمز المخزَّن في
            // الجلسة (دخول/تسجيل) ← الـIP. أول اثنين خادميّان لا يبلغهما تزوير الترويسة؛
            // ومن يدوّر جلسته يفقد payload الرمز فلا يبقى ما يخمّنه أصلاً.
            $anchor = $request->user()?->id
                ?? $request->session()->get('otp.requestId')
                ?? $request->session()->get('reg.requestId')
                ?? 'session|'.$request->session()->getId(); // لا الـIP: قابلٌ للتزوير خلف trustProxies

            return Limit::perMinute(5)->by('otp-verify|'.$anchor);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
