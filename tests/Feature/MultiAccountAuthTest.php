<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\EmailOtpService;
use App\Support\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** تعدّد الحسابات لنفس الهُويّة: مُنتقي الحساب عند الدخول + تبديل الحساب داخل المنصّة. */
class MultiAccountAuthTest extends TestCase
{
    use RefreshDatabase;

    /** ينشئ شخصاً بحسابين (إدارة + محامي) بنفس الهُويّة/الجوال وبريدين. */
    private function dualPerson(string $nid = '1055500001', string $phone = '0555500001'): array
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'national_id' => $nid, 'phone' => $phone, 'email' => 'p.admin@x.test']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'national_id' => $nid, 'phone' => $phone, 'email' => 'p.lawyer@x.test']);

        return [$admin, $lawyer, $nid];
    }

    public function test_login_with_multiple_accounts_shows_chooser_then_logs_in(): void
    {
        [$admin, $lawyer, $nid] = $this->dualPerson();
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/otp/request', ['national_id' => $nid])->assertRedirect(route('login'));
        $this->post('/auth/otp/verify', ['code' => '1234'])->assertRedirect(route('login'));

        // لم يُسجَّل الدخول بعد — بانتظار اختيار الحساب
        $this->assertGuest();
        $this->assertNotNull(session('account_choice'));

        // شاشة الدخول تعرض الحسابات
        $this->get('/login')->assertInertia(fn ($p) => $p->has('accountChoice', 2));

        // اختيار حساب المحامي → دخول للوحة المحامي
        $this->post('/auth/choose-account', ['account_id' => $lawyer->id])
            ->assertRedirect('/lawyer/dashboard');
        $this->assertAuthenticatedAs($lawyer);
    }

    public function test_single_account_logs_in_directly_without_chooser(): void
    {
        $user = User::factory()->create(['role' => Role::Client, 'national_id' => '1055500009', 'phone' => '0555500009']);
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/otp/request', ['national_id' => '1055500009']);
        $this->post('/auth/otp/verify', ['code' => '1234'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('account_choice'));
    }

    public function test_choose_account_rejects_account_of_another_identity(): void
    {
        [$admin, $lawyer, $nid] = $this->dualPerson();
        $intruder = User::factory()->create(['role' => Role::Lawyer, 'national_id' => '1066600002', 'phone' => '0566600002', 'email' => 'z@x.test']);
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/otp/request', ['national_id' => $nid]);
        $this->post('/auth/otp/verify', ['code' => '1234']);

        // محاولة اختيار حساب لا يخصّ الهُويّة المُتحقَّقة → مرفوض
        $this->post('/auth/choose-account', ['account_id' => $intruder->id])->assertSessionHasErrors('account_id');
        $this->assertGuest();
    }

    public function test_switch_account_between_same_person(): void
    {
        [$admin, $lawyer, $nid] = $this->dualPerson();

        $this->actingAs($admin)->post('/auth/switch-account', ['account_id' => $lawyer->id])
            ->assertRedirect('/lawyer/dashboard');
        $this->assertAuthenticatedAs($lawyer);
    }

    public function test_switch_account_rejects_other_person(): void
    {
        [$admin, $lawyer, $nid] = $this->dualPerson();
        $other = User::factory()->create(['role' => Role::Lawyer, 'national_id' => '1066600003', 'phone' => '0566600003', 'email' => 'o@x.test']);

        $this->actingAs($admin)->post('/auth/switch-account', ['account_id' => $other->id])->assertForbidden();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_sibling_accounts_are_shared_to_frontend(): void
    {
        [$admin, $lawyer, $nid] = $this->dualPerson();

        $this->actingAs($admin)->get('/admin/dashboard')
            ->assertInertia(fn ($p) => $p->where('auth.user.accounts', fn ($accounts) => count($accounts) === 2));
    }

    // 🔴 أمنيّ: حسابان بنفس الهُويّة لكن **جوال مختلف** لا يُجمَّعان (منع تصعيد الصلاحيّات)
    public function test_same_nid_different_phone_is_isolated(): void
    {
        $victim = User::factory()->create(['role' => Role::Admin, 'national_id' => '1099900001', 'phone' => '0599900001', 'email' => 'va@x.test']);
        $other = User::factory()->create(['role' => Role::Client, 'national_id' => '1099900001', 'phone' => '0599900002', 'email' => 'vc@x.test']);

        // التبديل ممنوع (جوال مختلف رغم نفس الهُويّة)
        $this->actingAs($other)->post('/auth/switch-account', ['account_id' => $victim->id])->assertForbidden();
        $this->assertAuthenticatedAs($other);

        // auth.accounts لا يتضمّن حساب الجوال المختلف (حساب واحد فقط = نفسه)
        $this->actingAs($other)->get('/dashboard')
            ->assertInertia(fn ($p) => $p->where('auth.user.accounts', fn ($a) => count($a) === 1));
    }

    /**
     * **رمز الجوال الثابت يعمل أينما ضُبط AUTH_DEV_OTP** (قرار المالك 2026-09-29: كما كان قبل `14c8f8a`،
     * لتجربة الدخول على سيرفر الاختبار) — وتجاوز البريد باقٍ محصوراً في التطوير كما كان.
     */
    public function test_sms_dev_bypass_works_wherever_configured_while_email_stays_dev_only(): void
    {
        config([
            'services.auth_dev_otp' => '1234',
            'app.url' => 'https://salaselbabel.net',
            'app.debug' => false,
        ]);
        $this->app['env'] = 'production';

        $this->assertTrue(app(OtpService::class)->devBypass(), 'الرمز الثابت للجوال لم يعمل على الخادم.');
        $this->assertTrue(OtpService::productionLike(), 'التنبيه في السجلّ يعتمد على كشف المضيف الإنتاجيّ');
        $this->assertFalse(app(EmailOtpService::class)->devBypass(), 'تجاوز البريد انفتح خارج التطوير.');

        // وبلا المتغيّر لا تجاوز — يمرّ الدخول بمزوّد الرسائل
        config(['services.auth_dev_otp' => null]);
        $this->assertFalse(app(OtpService::class)->devBypass());
    }

    /** القيمة "0" مملوءة لكنها زائفة — كان الحارس يفحص truthiness والتجاوز يفحص filled(). */
    public function test_zero_is_treated_consistently_by_guard_and_bypass(): void
    {
        config([
            'services.auth_dev_otp' => '0',
            'app.url' => 'https://law-laravel-office-new.test',
            'app.debug' => true,
        ]);
        $this->app['env'] = 'local';

        // مملوء ⇒ التجاوز فعّال ⇒ يجب أن يعتبره الحارس مضبوطاً أيضاً
        $this->assertTrue(app(OtpService::class)->devBypass());
        $this->assertTrue(OtpService::isDevOtpConfigured());
    }
}
