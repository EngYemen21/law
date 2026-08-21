<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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

        // تجاوز OTP التطويري (رمز ثابت لأي هوية) مسموح في local/testing فقط.
        // خطأ في APP_ENV على خادم الإنتاج كان يكفي لفتح دخول بلا رمز حقيقي — نُفشل الإقلاع بدل الصمت.
        if (config('services.auth_dev_otp') && ! app()->environment('local', 'testing')) {
            throw new \RuntimeException('AUTH_DEV_OTP مضبوط خارج بيئة التطوير — أزِله فوراً من ملف البيئة.');
        }

        // الإدارة العليا (enum Admin) تتجاوز كل الصلاحيات — يجعل $user->can(...) صحيحاً دائماً لها
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        // حدّ طلب رمز التحقّق (منع القصف): 3 طلبات/دقيقة بمفتاح الهويّة/الجوال + IP
        RateLimiter::for('otp-request', fn (Request $request) => Limit::perMinute(3)
            ->by((string) ($request->input('national_id') ?: $request->input('phone')).'|'.$request->ip()));

        // حدّ تأكيد الرمز/التبديل (منع التخمين): 5/دقيقة بمفتاح IP — مرساة ثابتة حاضرة في كل المسارات
        // (تحقّق الدخول/البريد، choose-account، switch-account) بلا اعتماد على requestId قد يغيب.
        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));
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
