<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\IntegrationSecret;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Payments\MoyasarGateway;
use App\Support\Integrations\IntegrationSecrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **مفاتيح الخدمات الخارجيّة من الشاشة** (قرار المالك 2026-09-30): مشفّرة في القاعدة، تتقدّم على `.env` متى
 * ضُبطت، والأسرار لا تصل المتصفّح، وكلّ حفظٍ يُقيَّد ويُشعَر به المديرون — للإدارة وحدها.
 */
class IntegrationSecretsTest extends TestCase
{
    use RefreshDatabase;

    private const SK = 'sk_test_ScreenKeyForTheTest1234';

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function save(User $by, array $changes, array $clear = [])
    {
        return $this->actingAs($by)->post(route('admin.integrations.update'), [
            'changes' => collect($changes)->map(fn ($v, $k) => ['key' => $k, 'value' => $v])->values()->all(),
            'clear' => $clear,
        ]);
    }

    public function test_a_saved_key_is_encrypted_at_rest_and_overrides_env(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_from_env']);

        $this->save($this->admin(), ['services.moyasar.secret_key' => self::SK])->assertRedirect(route('admin.integrations'));

        $raw = DB::table('integration_secrets')->where('key', 'services.moyasar.secret_key')->value('value');
        $this->assertStringNotContainsString(self::SK, (string) $raw, 'القيمة مخزّنة بلا تشفير');
        $this->assertSame(self::SK, IntegrationSecret::find('services.moyasar.secret_key')->value);

        IntegrationSecrets::apply();
        $this->assertSame(self::SK, config('services.moyasar.secret_key'));
        $this->assertTrue(app(MoyasarGateway::class)->isConfigured());
    }

    public function test_the_page_never_receives_a_secret_only_its_mask_and_source(): void
    {
        config(['services.moyasar.webhook_secret' => 'whsec_from_env_value_1234', 'services.zoom.client_id' => 'zoom-client-plain']);
        IntegrationSecrets::put('services.moyasar.secret_key', self::SK, $admin = $this->admin());
        IntegrationSecrets::apply();

        $response = $this->actingAs($admin)->get(route('admin.integrations'));
        $response->assertOk();
        $this->assertStringNotContainsString(self::SK, $response->getContent());
        $this->assertStringNotContainsString('whsec_from_env_value_1234', $response->getContent());

        // مسارات الإعداد فيها نقاط يقرؤها مُثبِت Inertia تداخلاً — فتُقرأ الخصائص كما وصلت الصفحة
        $states = $response->viewData('page')['props']['states'];
        $this->assertSame(['source' => 'screen', 'display' => '••••1234'], $states['services.moyasar.secret_key']);
        $this->assertSame(['source' => 'env', 'display' => '••••1234'], $states['services.moyasar.webhook_secret']);
        $this->assertSame('zoom-client-plain', $states['services.zoom.client_id']['display']);
        $this->assertSame('none', $states['services.taqnyat.api_key']['source']);
    }

    public function test_clearing_a_key_returns_the_service_to_env(): void
    {
        $admin = $this->admin();
        IntegrationSecrets::put('services.moyasar.secret_key', self::SK, $admin);

        $this->save($admin, [], ['services.moyasar.secret_key'])->assertRedirect();

        $this->assertNull(IntegrationSecret::find('services.moyasar.secret_key'));
        config(['services.moyasar.secret_key' => 'sk_test_from_env']);
        IntegrationSecrets::apply();
        $this->assertSame('sk_test_from_env', config('services.moyasar.secret_key'));
    }

    public function test_every_save_is_audited_and_notifies_admins_without_the_value(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $this->save($admin, ['services.moyasar.secret_key' => self::SK, 'services.taqnyat.sender' => 'SALASEL'])->assertRedirect();

        $log = AuditLog::where('action', 'تحديث مفاتيح الخدمات الخارجيّة')->sole();
        $this->assertStringContainsString('المفتاح السرّيّ', $log->description);
        $this->assertStringNotContainsString(self::SK, $log->description);
        foreach ([$admin, $other] as $user) {
            $note = UserNotification::where('user_id', $user->id)->where('body', 'like', '%مفاتيح الخدمات الخارجيّة%')->sole();
            $this->assertStringNotContainsString(self::SK, $note->body);
        }
    }

    public function test_malformed_or_unknown_keys_are_refused_and_nothing_is_written(): void
    {
        $admin = $this->admin();

        $this->save($admin, ['services.moyasar.secret_key' => 'not-a-moyasar-key', 'services.taqnyat.sender' => 'OK'])
            ->assertSessionHasErrors('changes.services.moyasar.secret_key');
        $this->save($admin, ['app.key' => 'base64:hijack'])->assertSessionHasErrors();
        $this->save($admin, ['mail.default' => 'sendmail'])->assertSessionHasErrors('changes.mail.default');

        $this->assertSame(0, IntegrationSecret::count());
    }

    public function test_only_admins_reach_the_keys(): void
    {
        foreach ([Role::Employee, Role::Lawyer, Role::Client] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertPageRefused($this->actingAs($user)->get(route('admin.integrations')));
            $this->actingAs($user)->post(route('admin.integrations.update'), ['changes' => [['key' => 'services.moyasar.secret_key', 'value' => self::SK]]]);
        }

        $this->assertSame(0, IntegrationSecret::count());
    }

    public function test_a_value_that_can_no_longer_be_decrypted_falls_back_to_env(): void
    {
        DB::table('integration_secrets')->insert(['key' => 'services.moyasar.secret_key', 'value' => 'garbage-not-encrypted', 'created_at' => now(), 'updated_at' => now()]);
        config(['services.moyasar.secret_key' => 'sk_test_from_env']);

        IntegrationSecrets::apply();

        $this->assertSame('sk_test_from_env', config('services.moyasar.secret_key'));
    }
}
