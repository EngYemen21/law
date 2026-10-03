<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\VerificationCodeMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * **العميل يؤكّد بريده قبل استعمال حسابه** (قرار المالك 2026-10-03: الخيار «أ» إلزاميّاً، مع أمرين).
 *
 * الحساب الذي تُنشئه الإدارة يصل بريده وجواله غير مؤكَّدين، ولا شيء كان يؤكّدهما. الآن: الدخول برمز الجوال
 * يؤكّد الجوال، وصفحة «أكّد بريدك» إلزاميّة حتى يُدخل العميل رمزاً وصل بريده (مع تصحيح البريد إن كان خطأً)،
 * والإدارة ترسل الرمز من ملفّ العميل.
 */
class ClientEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function unverifiedClient(array $attrs = []): User
    {
        return User::factory()->unverified()->create(['role' => Role::Client, 'phone_verified_at' => null] + $attrs);
    }

    /** الرمز الذي أُرسل في آخر رسالة تأكيد. */
    private function lastCode(): string
    {
        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return (string) $code;
    }

    public function test_logging_in_by_phone_code_verifies_the_phone(): void
    {
        $client = $this->unverifiedClient(['national_id' => '1098765400', 'phone' => '0555500400']);

        $this->loginViaOtp($client);
        $this->assertAuthenticatedAs($client);

        $this->assertNotNull($client->fresh()->phone_verified_at);
    }

    public function test_an_unverified_client_is_held_on_the_verify_page(): void
    {
        $client = $this->unverifiedClient();

        $this->actingAs($client)->get('/dashboard')->assertRedirect(route('email.verify'));
        $this->actingAs($client)->get('/myconsults')->assertRedirect(route('email.verify'));
        $this->actingAs($client)->getJson('/notifications')->assertStatus(409);
        $this->actingAs($client)->get(route('email.verify'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/verify-email')->where('email', $client->email)->where('sentAt', null));
        $this->actingAs($client)->post(route('logout'))->assertRedirect();
    }

    public function test_staff_and_verified_clients_pass_untouched(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Client]))->get('/dashboard')->assertOk();
        $this->actingAs(User::factory()->unverified()->create(['role' => Role::Employee]))->get('/employee/dashboard')->assertOk();
        $this->actingAs(User::factory()->create(['role' => Role::Client]))->get(route('email.verify'))->assertRedirect();
    }

    public function test_the_client_confirms_with_the_code_sent_to_the_email(): void
    {
        $client = $this->unverifiedClient();

        $this->actingAs($client)->post(route('email.verify.send'))->assertSessionHasNoErrors();
        Mail::assertSent(VerificationCodeMail::class, fn ($m) => $m->hasTo($client->email));
        $code = $this->lastCode();

        $wrong = $code === '1111' ? '2222' : '1111';
        $this->actingAs($client)->post(route('email.verify.confirm'), ['code' => $wrong])->assertSessionHasErrors('code');
        $this->assertNull($client->fresh()->email_verified_at);

        $this->actingAs($client)->post(route('email.verify.confirm'), ['code' => $code])->assertRedirect('/dashboard');
        $this->assertNotNull($client->fresh()->email_verified_at);
        $this->actingAs($client->fresh())->get('/dashboard')->assertOk();
        $this->assertTrue(AuditLog::where('action', 'تأكيد البريد الإلكتروني')->exists());
    }

    /** حدّ المحاولات على الرمز نفسه — مستقلٌّ عن حدّ المعدّل بالدقيقة (يُرفع هنا ليُختبر الأوّل وحده). */
    public function test_a_code_stops_working_after_too_many_wrong_attempts(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $client = $this->unverifiedClient();
        $this->actingAs($client)->post(route('email.verify.send'));
        $code = $this->lastCode();
        $wrong = $code === '1111' ? '2222' : '1111';

        foreach (range(1, 5) as $i) {
            $this->actingAs($client)->post(route('email.verify.confirm'), ['code' => $wrong]);
        }
        $this->actingAs($client)->post(route('email.verify.confirm'), ['code' => $code])->assertSessionHasErrors('code');

        $this->assertNull($client->fresh()->email_verified_at);
    }

    public function test_a_wrong_email_is_corrected_and_the_old_code_dies(): void
    {
        $client = $this->unverifiedClient(['email' => 'typo@exmple.test']);
        User::factory()->create(['role' => Role::Client, 'email' => 'taken@example.test']);
        $this->actingAs($client)->post(route('email.verify.send'));
        $oldCode = $this->lastCode();

        $this->actingAs($client)->post(route('email.verify.change'), ['email' => 'taken@example.test'])->assertSessionHasErrors('email');
        $this->actingAs($client)->post(route('email.verify.change'), ['email' => 'right@example.test'])->assertSessionHasNoErrors();

        $fresh = $client->fresh();
        $this->assertSame('right@example.test', $fresh->email);
        $this->assertNull($fresh->email_verified_at);
        Mail::assertSent(VerificationCodeMail::class, fn ($m) => $m->hasTo('right@example.test'));

        if ($oldCode !== $this->lastCode()) {
            $this->actingAs($fresh)->post(route('email.verify.confirm'), ['code' => $oldCode])->assertSessionHasErrors('code');
        }
        $this->actingAs($fresh)->post(route('email.verify.confirm'), ['code' => $this->lastCode()])->assertRedirect('/dashboard');
        $this->assertNotNull($fresh->fresh()->email_verified_at);
    }

    public function test_sending_is_limited_per_hour(): void
    {
        $client = $this->unverifiedClient();

        foreach (range(1, 4) as $i) {
            $this->actingAs($client)->post(route('email.verify.send'))->assertSessionHasNoErrors();
        }
        $this->actingAs($client)->post(route('email.verify.send'))->assertSessionHasErrors('email');

        Mail::assertSentCount(4);
    }

    public function test_a_verified_client_cannot_use_the_actions(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->post(route('email.verify.send'))->assertForbidden();
        $this->actingAs($client)->post(route('email.verify.change'), ['email' => 'new@example.test'])->assertForbidden();
        Mail::assertNothingSent();
    }

    /** الإدارة ترسل الرمز من ملفّ العميل، والعميل يُدخله في صفحته. */
    public function test_the_admin_sends_the_code_and_the_client_uses_it(): void
    {
        $client = $this->unverifiedClient();
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.clients.verify-email.send', $client))->assertSessionHasNoErrors();
        Mail::assertSent(VerificationCodeMail::class, fn ($m) => $m->hasTo($client->email));
        $this->assertTrue(AuditLog::where('action', 'إرسال رمز تأكيد البريد')->exists());

        $this->actingAs($client)->get(route('email.verify'))->assertInertia(fn ($page) => $page->whereNot('sentAt', null));
        $this->actingAs($client)->post(route('email.verify.confirm'), ['code' => $this->lastCode()])->assertRedirect('/dashboard');
        $this->assertNotNull($client->fresh()->email_verified_at);
    }
}
