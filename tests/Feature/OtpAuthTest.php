<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create([
            'role' => Role::Client, 'national_id' => '1122334455', 'phone' => '0555550001',
        ]);
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_request_otp_calls_taqnyat_verify_generate(): void
    {
        $this->fakeTaqnyatVerify();
        $this->client();

        $this->post('/auth/otp/request', ['national_id' => '1122334455'])
            ->assertRedirect(route('login'));

        // تُنادى واجهة Verify للتوليد (بلا activeKey) بالرقم الدوليّ وrequestId
        Http::assertSent(function ($req) {
            $p = $req->data()[0] ?? [];

            return str_contains($req->url(), '/verify.php')
                && $p['numbers'] === ['966555550001']
                && ! isset($p['activeKey'])
                && ! empty($p['requestId']);
        });
        $this->assertNotEmpty(session('otp')['requestId'] ?? null);
    }

    public function test_request_blocked_when_taqnyat_not_configured(): void
    {
        Http::fake();
        $this->client();

        $this->post('/auth/otp/request', ['national_id' => '1122334455'])
            ->assertSessionHasErrors('national_id');
        Http::assertNothingSent();
        $this->assertGuest();
    }

    /**
     * 🔴 كانت ثلاث رسائل مختلفة («لا يوجد حساب» · «موقوف» · «لا جوال») تكشف أيّ أرقام الهويّة
     * مسجّلة ومن منها موقوف. الآن: الردّ نفسه، وشاشة الرمز نفسها، ورسالة الرمز الخاطئ نفسها — بلا إرسال.
     */
    public function test_unknown_suspended_and_phoneless_identities_get_the_same_response_as_a_real_one(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->fakeTaqnyatVerify('1234');
        User::factory()->create(['role' => Role::Employee, 'national_id' => '2000000011', 'phone' => '0590000011', 'status' => 'suspended']);
        User::factory()->create(['role' => Role::Client, 'national_id' => '2000000012', 'phone' => null]);

        foreach (['9999999999', '2000000011', '2000000012'] as $nid) {
            $this->flushSession();

            $this->post('/auth/otp/request', ['national_id' => $nid])
                ->assertRedirect(route('login'))
                ->assertSessionHasNoErrors();

            $this->get('/login')->assertInertia(fn (Assert $page) => $page
                ->where('authState.step', 'otp')
                ->where('authState.mode', 'login')
                ->where('authState.maskedTarget', null));

            $this->post('/auth/otp/verify', ['code' => '1234'])
                ->assertSessionHasErrors(['code' => 'رمز التحقّق غير صحيح أو منتهٍ.']);
            $this->assertGuest();
        }

        Http::assertNothingSent();
    }

    /** الحساب الحقيقيّ لا يُعرض له آخر أرقام الجوال في الدخول — عرضها وحده يُثبت وجود الحساب. */
    public function test_a_real_identity_does_not_reveal_its_phone_digits(): void
    {
        $this->client();
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/otp/request', ['national_id' => '1122334455'])
            ->assertRedirect(route('login'))
            ->assertSessionHasNoErrors();

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('authState.step', 'otp')
            ->where('authState.maskedTarget', null));
    }

    /** 🔴 كلّ إعادة إرسال كانت تُصفّر المحاولات الخمس بلا سقف ⇒ تخمين تراكميّ وقصف الجوال. */
    public function test_login_code_resend_is_capped_per_operation(): void
    {
        $this->client();
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/otp/request', ['national_id' => '1122334455']); // الإصدار الأوّل
        for ($i = 2; $i <= OtpService::MAX_ISSUES; $i++) {
            $this->travel(61)->seconds();
            $this->post('/auth/otp/resend')->assertSessionHasNoErrors();
        }

        $this->travel(61)->seconds();
        $this->post('/auth/otp/resend')->assertSessionHasErrors('code');

        $generated = Http::recorded(fn ($req) => ! isset(($req->data()[0] ?? [])['activeKey']))->count();
        $this->assertSame(OtpService::MAX_ISSUES, $generated, 'أُرسلت رسائل بعد بلوغ السقف.');
    }

    /** الهويّة غير المسجّلة تُعامَل في إعادة الإرسال كالمسجّلة: السقف نفسه، ولا رسالة تُرسل. */
    public function test_unknown_identity_resend_is_capped_the_same_way_and_sends_nothing(): void
    {
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/otp/request', ['national_id' => '9999999999']);
        for ($i = 2; $i <= OtpService::MAX_ISSUES; $i++) {
            $this->travel(61)->seconds();
            $this->post('/auth/otp/resend')->assertSessionHasNoErrors();
        }

        $this->travel(61)->seconds();
        $this->post('/auth/otp/resend')->assertSessionHasErrors('code');
        Http::assertNothingSent();
    }

    public function test_malformed_national_id_is_rejected(): void
    {
        $this->post('/auth/otp/request', ['national_id' => '12ab'])->assertSessionHasErrors('national_id');
        $this->post('/auth/otp/request', ['national_id' => ''])->assertSessionHasErrors('national_id');
        $this->assertGuest();
    }

    public function test_correct_code_logs_in_client_and_redirects_home(): void
    {
        $user = $this->client();
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/otp/request', ['national_id' => '1122334455']);
        $this->post('/auth/otp/verify', ['code' => '1234'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        // نُودِيت واجهة Verify للتحقّق مع activeKey
        Http::assertSent(fn ($req) => isset(($req->data()[0] ?? [])['activeKey']));
    }

    public function test_wrong_code_is_rejected(): void
    {
        $this->client();
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/otp/request', ['national_id' => '1122334455']);
        $this->post('/auth/otp/verify', ['code' => '9999'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_correct_code_routes_admin_to_admin_home(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'national_id' => '1000009999', 'phone' => '0555559999']);
        $this->fakeTaqnyatVerify('1234');

        $this->post('/auth/otp/request', ['national_id' => '1000009999']);
        $this->post('/auth/otp/verify', ['code' => '1234'])->assertRedirect('/admin/dashboard');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_malformed_code_is_rejected(): void
    {
        $this->client();
        $this->fakeTaqnyatVerify();
        $this->post('/auth/otp/request', ['national_id' => '1122334455']);

        $this->post('/auth/otp/verify', ['code' => '12'])->assertSessionHasErrors('code');
        $this->post('/auth/otp/verify', ['code' => 'abcd'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_verify_is_rate_limited(): void
    {
        $this->client();
        $this->fakeTaqnyatVerify('1234');
        $this->post('/auth/otp/request', ['national_id' => '1122334455']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/auth/otp/verify', ['code' => '9999'])->assertStatus(302);
        }
        $this->post('/auth/otp/verify', ['code' => '9999'])->assertStatus(429);
    }

    /**
     * 🔴 المفتاح كان الـIP وحده، و trustProxies(at:'*') يجعل $request->ip() قيمةً يرسلها
     * العميل في X-Forwarded-For. فتدوير الترويسة كان يمنح حصّة جديدة كل مرة ⇒ تخمين
     * بلا حدّ لرمز من أربعة أرقام. المرساة الآن الجلسة (لا تُزوَّر) إلى جانب الـIP.
     */
    public function test_forged_forwarded_for_does_not_reset_the_verify_quota(): void
    {
        $this->client();
        $this->fakeTaqnyatVerify('1234');
        $this->post('/auth/otp/request', ['national_id' => '1122334455']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/auth/otp/verify', ['code' => '9999'], ['X-Forwarded-For' => '203.0.113.'.$i])
                ->assertStatus(302);
        }

        $blocked = $this->post('/auth/otp/verify', ['code' => '9999'], ['X-Forwarded-For' => '203.0.113.99']);

        $this->assertSame(429, $blocked->getStatusCode(), 'تدوير X-Forwarded-For جدّد حصّة التخمين.');
    }

    /**
     * 🔴 فشل الرمز كان لا يزيد عدّاداً ولا يُبطل حمولة الجلسة، فيُعاد استعمال نفس requestId
     * بلا نهاية — ورمز من أربعة أرقام مساحته 10000 احتمال. المسار البريدي فيه MAX_ATTEMPTS
     * منذ البداية؛ المسار الهاتفي لم يكن فيه شيء. بعد الحدّ تُبطَل الحمولة ويلزم إعادة إرسال.
     */
    public function test_wrong_code_attempts_are_capped_and_invalidate_the_session_code(): void
    {
        // حدّ المعدّل (5/دقيقة) يُعطَّل هنا كي يُقاس السقف وحده — وله اختباره المستقلّ أعلاه
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->client();
        $this->fakeTaqnyatVerify('1234');
        $this->post('/auth/otp/request', ['national_id' => '1122334455']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/auth/otp/verify', ['code' => '9999'])->assertSessionHasErrors('code');
        }

        // حتى الرمز الصحيح لم يعد يُقبل — الحمولة عُطّلت (ولا تُحذف كي تبقى مرساة حدّ المعدّل)
        $this->post('/auth/otp/verify', ['code' => '1234'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertSame(5, session('otp')['attempts'] ?? 0, 'عدّاد المحاولات لم يُسجَّل.');
    }
}
