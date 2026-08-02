<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_unknown_national_id_is_rejected(): void
    {
        $this->fakeTaqnyatVerify();

        $this->post('/auth/otp/request', ['national_id' => '9999999999'])
            ->assertSessionHasErrors('national_id');
        $this->assertGuest();
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
}
