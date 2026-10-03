<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **الإدارة تُنشئ حساب عميل** (طلب المالك 2026-10-03) — بقواعد التسجيل الذاتيّ نفسها (`ClientAccount`).
 *
 * كان حساب العميل لا يُنشأ إلّا بتسجيله بنفسه. الآن زرّ «إنشاء حساب عميل» في صفحة العملاء: الاسم والهويّة
 * والجوال والبريد، بلا تكرارٍ ولا انتحالٍ لهويّة الطاقم، والعمليّة في سجلّ التدقيق.
 */
class AdminCreateClientTest extends TestCase
{
    use RefreshDatabase;

    private const VALID = ['name' => 'سارة العتيبي', 'national_id' => '1098765432', 'phone' => '0555512345', 'email' => 'sara@example.test'];

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    public function test_the_admin_creates_an_active_unverified_client_and_lands_on_its_file(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.clients.store'), self::VALID);

        $client = User::where('national_id', '1098765432')->firstOrFail();
        $response->assertRedirect(route('admin.clients.show', $client));
        $this->assertSame(Role::Client, $client->role);
        $this->assertSame('active', $client->status);
        $this->assertSame('0555512345', $client->phone);
        $this->assertNull($client->phone_verified_at, 'الجوال غير مؤكَّد حتى يدخل صاحبه');
        $this->assertNull($client->email_verified_at);

        $log = AuditLog::where('action', 'إنشاء حساب عميل')->firstOrFail();
        $this->assertSame('العملاء', $log->category);
        $this->assertStringNotContainsString('0555512345', (string) json_encode($log->after_state), 'الجوال مقنّعٌ في السجلّ');
    }

    public function test_the_same_rules_as_self_registration_apply(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.clients.store'), ['name' => 'سارة', 'national_id' => '12345', 'phone' => '123', 'email' => 'x'])
            ->assertSessionHasErrors(['name', 'national_id', 'phone', 'email']);

        $this->assertSame(0, User::where('role', Role::Client)->count());
    }

    /** الجوال يُقارن بصيغه كلّها — `9665…` و`05…` رقمٌ واحد. */
    public function test_a_duplicate_client_is_refused_in_any_phone_format(): void
    {
        User::factory()->create(['role' => Role::Client, 'national_id' => '1000000001', 'phone' => '966555512345', 'email' => 'old@example.test']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.clients.store'), self::VALID)->assertSessionHasErrors('phone');
        $this->actingAs($admin)->post(route('admin.clients.store'), ['national_id' => '1000000001', 'phone' => '0555500000'] + self::VALID)->assertSessionHasErrors('national_id');
        $this->actingAs($admin)->post(route('admin.clients.store'), ['email' => 'old@example.test', 'phone' => '0555500000'] + self::VALID)->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('role', Role::Client)->count());
    }

    public function test_a_staff_identity_cannot_become_a_client_account(): void
    {
        User::factory()->create(['role' => Role::Lawyer, 'national_id' => '1098765432', 'phone' => '0500000000']);

        $this->actingAs($this->admin())->post(route('admin.clients.store'), self::VALID)->assertSessionHasErrors('national_id');

        $this->assertSame(0, User::where('role', Role::Client)->count());
    }

    public function test_only_the_admin_can_create(): void
    {
        foreach ([Role::Employee, Role::Lawyer, Role::Client] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->post(route('admin.clients.store'), self::VALID)->assertRedirect();
        }

        $this->assertNull(User::where('national_id', '1098765432')->first());
    }

    /** يدخل العميل الجديد بالطريق المعتاد: هويّته ثمّ رمزٌ إلى جواله. */
    public function test_the_new_client_logs_in_with_id_and_a_code(): void
    {
        $this->actingAs($this->admin())->post(route('admin.clients.store'), self::VALID);
        auth()->logout();
        $client = User::where('national_id', '1098765432')->firstOrFail();

        $this->loginViaOtp($client)->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($client);
    }
}
