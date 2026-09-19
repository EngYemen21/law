<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\VerificationCodeMail;
use App\Models\User;
use App\Support\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationOtpTest extends TestCase
{
    use RefreshDatabase;

    private array $valid = [
        'name' => 'عبدالله محمد العتيبي',
        'national_id' => '1088452213',
        'phone' => '0555555545',
        'email' => 'newclient@example.com',
    ];

    /** استخراج رمز البريد من الرسالة المُرسَلة (Mail::fake). */
    private function sentEmailCode(): string
    {
        return Mail::sent(VerificationCodeMail::class)->first()->code;
    }

    public function test_new_client_created_after_phone_then_email(): void
    {
        Mail::fake();
        $this->fakeTaqnyatVerify('1234'); // رمز الجوال الصحيح = 1234

        // 1) البيانات → رمز الجوال
        $this->post('/auth/register', $this->valid)->assertRedirect(route('login'));
        $this->assertSame('sms', session('otp')['channel']);

        // 2) تأكيد الجوال (تقنيات) → إرسال رمز البريد (Resend)
        $this->post('/auth/register/verify-phone', ['code' => '1234'])->assertRedirect(route('login'));
        $this->assertSame('email', session('otp')['channel']);
        Mail::assertSent(VerificationCodeMail::class);

        // 3) تأكيد البريد → إنشاء الحساب والدخول
        $this->post('/auth/register/verify-email', ['code' => $this->sentEmailCode()])
            ->assertRedirect('/dashboard');

        $user = User::where('email', 'newclient@example.com')->firstOrFail();
        $this->assertSame(Role::Client, $user->role);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_phone_code_stops_before_email(): void
    {
        Mail::fake();
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/register', $this->valid);
        $this->post('/auth/register/verify-phone', ['code' => '9999'])->assertSessionHasErrors('code');

        Mail::assertNothingSent();
        $this->assertSame('sms', session('otp')['channel']);
        $this->assertDatabaseMissing('users', ['email' => 'newclient@example.com']);
    }

    public function test_wrong_email_code_does_not_create_account(): void
    {
        Mail::fake();
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/register', $this->valid);
        $this->post('/auth/register/verify-phone', ['code' => '1234']);
        $this->post('/auth/register/verify-email', ['code' => '0000'])->assertSessionHasErrors('code');

        $this->assertDatabaseMissing('users', ['email' => 'newclient@example.com']);
        $this->assertGuest();
    }

    /** إعادة إرسال رمز الجوال في التسجيل لها السقف نفسه (نظير سقف رمز البريد). */
    public function test_phone_code_resend_is_capped_during_registration(): void
    {
        Mail::fake();
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/register', $this->valid); // الإصدار الأوّل
        for ($i = 2; $i <= OtpService::MAX_ISSUES; $i++) {
            $this->travel(61)->seconds();
            $this->post('/auth/otp/resend')->assertSessionHasNoErrors();
        }

        $this->travel(61)->seconds();
        $this->post('/auth/otp/resend')->assertSessionHasErrors('code');
        $this->assertDatabaseMissing('users', ['email' => 'newclient@example.com']);
    }

    public function test_registration_blocked_without_taqnyat_keys(): void
    {
        // بلا مفاتيح تقنيات: يُمنع بدء التسجيل
        $this->post('/auth/register', $this->valid)->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_duplicate_national_id_is_rejected(): void
    {
        User::factory()->create(['national_id' => '1088452213', 'phone' => '0533334444']);
        $this->post('/auth/register', $this->valid)->assertSessionHasErrors('national_id');
    }

    public function test_duplicate_phone_is_rejected(): void
    {
        User::factory()->create(['national_id' => '1999999999', 'phone' => '0555555545']);
        $this->post('/auth/register', $this->valid)->assertSessionHasErrors('phone');
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'newclient@example.com']);
        $this->post('/auth/register', $this->valid)->assertSessionHasErrors('email');
    }

    public function test_invalid_phone_format_is_rejected(): void
    {
        $this->post('/auth/register', [...$this->valid, 'phone' => '12345'])->assertSessionHasErrors('phone');
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->post('/auth/register', [...$this->valid, 'email' => 'not-an-email'])->assertSessionHasErrors('email');
    }

    public function test_single_word_name_is_rejected(): void
    {
        $this->post('/auth/register', [...$this->valid, 'name' => 'عبدالله'])->assertSessionHasErrors('name');
    }

    public function test_missing_fields_are_rejected(): void
    {
        $this->post('/auth/register', [])->assertSessionHasErrors(['name', 'national_id', 'phone', 'email']);
        $this->assertGuest();
    }

    // 🔴 أمنيّ: منع التسجيل الذاتي بهُويّة تخصّ حساب موظف/إدارة (انتحال هُويّة الطاقم)
    public function test_registration_blocked_for_staff_identity(): void
    {
        User::factory()->create(['role' => Role::Lawyer, 'national_id' => '1088452213', 'phone' => '0533334444']);

        $this->post('/auth/register', $this->valid)->assertSessionHasErrors('national_id');
        $this->assertDatabaseMissing('users', ['email' => 'newclient@example.com']);
    }
}
