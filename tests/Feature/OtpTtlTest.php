<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\VerificationCodeMail;
use App\Models\Setting;
use App\Models\User;
use App\Support\EmailOtpService;
use App\Support\OtpService;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * **صلاحيّة رمز التحقّق إعدادٌ واحد افتراضه ٥ دقائق** (قرار المالك 2026-10-01).
 *
 * كانت ١٠ منقوشةً لرمز البريد ولنافذة تغيير الجوال، ورمز الجوال في الدخول والتسجيل **بلا حدٍّ من عندنا**:
 * «تقنيات» تولّده وتتحقّق منه ولا تقبل مدّةً نضبطها — فالحدّ يُفرض من وقت الإرسال المحفوظ في الجلسة.
 */
class OtpTtlTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_is_declared_with_five_minute_default(): void
    {
        $this->assertSame('security', SettingsRegistry::field('otp_ttl_minutes')['group']);
        $this->assertSame(5, OtpService::TTL_MINUTES);
        $this->assertSame(5, OtpService::ttlMinutes());
    }

    public function test_login_code_is_refused_after_it_expires(): void
    {
        User::factory()->create(['role' => Role::Client, 'national_id' => '1077700001', 'phone' => '0555700001']);
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/otp/request', ['national_id' => '1077700001'])->assertRedirect(route('login'));
        $this->travel(6)->minutes();

        $this->post('/auth/otp/verify', ['code' => '1234'])->assertSessionHasErrors(['code' => 'انتهت صلاحية الرمز — اطلب رمزاً جديداً.']);
        $this->assertGuest();
    }

    public function test_login_code_is_accepted_inside_its_window(): void
    {
        User::factory()->create(['role' => Role::Client, 'national_id' => '1077700002', 'phone' => '0555700002']);
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/otp/request', ['national_id' => '1077700002']);
        $this->travel(4)->minutes();

        $this->post('/auth/otp/verify', ['code' => '1234'])->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_window_follows_the_setting(): void
    {
        Setting::put('otp_ttl_minutes', 3);
        User::factory()->create(['role' => Role::Client, 'national_id' => '1077700003', 'phone' => '0555700003']);
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/otp/request', ['national_id' => '1077700003']);
        $this->travel(4)->minutes();

        $this->post('/auth/otp/verify', ['code' => '1234'])->assertSessionHasErrors(['code' => 'انتهت صلاحية الرمز — اطلب رمزاً جديداً.']);
        $this->assertGuest();
    }

    /** خمس دقائق سقفٌ أمنيّ (قرار المالك 2026-10-02): لا يُحفظ ما فوقها، وقيمةٌ أقدم في القاعدة تُقيَّد بها. */
    public function test_the_window_never_exceeds_five_minutes(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->post(route('admin.settings.update'), ['otp_ttl_minutes' => 6])->assertSessionHasErrors('otp_ttl_minutes');

        Setting::put('otp_ttl_minutes', 10);
        SettingsRegistry::flush();
        $this->assertSame(5, OtpService::ttlMinutes());
    }

    public function test_email_code_uses_the_same_window_and_says_so(): void
    {
        $payload = app(EmailOtpService::class)->issue('someone@example.com', 'عميل');

        $this->assertEqualsWithDelta(now()->addMinutes(5)->timestamp, Carbon::parse($payload['expires_at'])->timestamp, 2);

        $html = (new VerificationCodeMail('1234', 'عميل', 'تأكيد بريدك الإلكتروني', OtpService::ttlMinutes()))->render();
        $this->assertStringContainsString('لمدة 5 دقائق', $html);
    }
}
