<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;
use App\Services\MoyasarService;
use App\Support\AppEnvironment;
use App\Support\EnvironmentAudit;
use App\Support\OtpService;
use App\Support\PdfRenderer;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RichDemoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **فصل التطوير عن الإنتاج — المرحلة ١** (قرار المالك 2026-09-29). «ما لم يُعلَن صندوقَ تجربةٍ فهو إنتاج»
 * (`AppEnvironment`): الرمز الثابت وتصفير القاعدة والبذور التجريبيّة ومفتاح ميسّر الحقيقيّ كلٌّ في مكانه، و
 * `env:check` يكشف الإعداد الخاطئ.
 */
class EnvironmentSeparationTest extends TestCase
{
    use RefreshDatabase;

    /** إعدادُ إنتاجٍ سليم — كلّ اختبارٍ يُفسد منه بنداً واحداً. */
    private function healthyProduction(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.debug' => false,
            'app.url' => 'https://salaselbabel.net',
            'services.auth_dev_otp' => null,
            'app.allow_db_reset' => false,
            'mail.default' => 'resend',
            'services.taqnyat.api_key' => 'tq_key',
            'services.moyasar.secret_key' => 'sk_live_abc',
            'services.moyasar.publishable_key' => 'pk_live_abc',
            'services.moyasar.webhook_secret' => 'whsec',
            'services.zoom.client_id' => null,
            'logging.channels.single.level' => 'warning',
            'logging.channels.daily.level' => 'warning',
        ]);
    }

    /** @return list<string> */
    private function failingKeys(): array
    {
        return array_values(array_map(fn (array $f) => $f['key'], array_filter(EnvironmentAudit::findings(), fn (array $f) => $f['level'] === 'fail')));
    }

    public function test_only_declared_sandboxes_count_as_sandbox(): void
    {
        foreach (['local', 'testing', 'staging'] as $env) {
            $this->app['env'] = $env;
            $this->assertTrue(AppEnvironment::isSandbox(), $env);
        }

        foreach (['production', 'prod', 'live', ''] as $env) {
            $this->app['env'] = $env;
            $this->assertTrue(AppEnvironment::isProduction(), "«{$env}» يجب أن يُعدّ إنتاجاً");
        }
    }

    public function test_fixed_login_code_is_sandbox_only_and_hidden_from_the_login_page(): void
    {
        config(['services.auth_dev_otp' => '1234']);

        $this->app['env'] = 'local';
        $this->assertTrue(app(OtpService::class)->devBypass());
        $this->get('/login')->assertInertia(fn ($p) => $p->where('devOtp', '1234'));

        foreach (['production', 'prod'] as $env) {
            $this->app['env'] = $env;
            $this->assertFalse(app(OtpService::class)->devBypass(), $env);
            $this->get('/login')->assertInertia(fn ($p) => $p->where('devOtp', null)->where('appEnv.sandbox', false));
        }
    }

    public function test_database_reset_is_refused_under_an_undeclared_environment(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->app['env'] = 'prod';
        // خارج `testing` يعود حارس CSRF — والمقصود هنا حارس البيئة وحده
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->actingAs($admin)->post(route('admin.reset-database'), ['confirm' => 'RESET'])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_env_check_passes_a_healthy_production_and_names_each_violation(): void
    {
        $this->healthyProduction();
        $this->assertSame([], $this->failingKeys());
        $this->artisan('env:check')->assertSuccessful();

        $violations = [
            'APP_DEBUG' => ['app.debug' => true],
            'APP_URL' => ['app.url' => 'http://127.0.0.1:8000'],
            'AUTH_DEV_OTP' => ['services.auth_dev_otp' => '1234'],
            'ALLOW_DB_RESET' => ['app.allow_db_reset' => true],
            'MAIL_MAILER' => ['mail.default' => 'log'],
            'TAQNYAT_API_KEY' => ['services.taqnyat.api_key' => null],
            'MOYASAR_SECRET_KEY' => ['services.moyasar.secret_key' => 'sk_test_abc'],
            'MOYASAR_WEBHOOK_SECRET' => ['services.moyasar.webhook_secret' => null],
            'ZOOM_WEBHOOK_SECRET' => ['services.zoom.client_id' => 'zc', 'services.zoom.webhook_secret' => null],
        ];

        foreach ($violations as $key => $bad) {
            $this->healthyProduction();
            config($bad);
            $this->assertSame([$key], $this->failingKeys(), $key);
        }

        $this->healthyProduction();
        config(['app.debug' => true]);
        $this->artisan('env:check')->assertFailed();
    }

    public function test_live_payment_key_is_refused_in_the_sandbox_only(): void
    {
        config(['services.moyasar.secret_key' => 'sk_live_abc']);
        $this->assertFalse(app(MoyasarService::class)->isConfigured());
        $this->assertSame(['MOYASAR_SECRET_KEY'], $this->failingKeys());

        config(['services.moyasar.secret_key' => 'sk_test_abc']);
        $this->assertTrue(app(MoyasarService::class)->isConfigured());

        $this->app['env'] = 'production';
        config(['services.moyasar.secret_key' => 'sk_live_abc']);
        $this->assertTrue(app(MoyasarService::class)->isConfigured());
    }

    public function test_webhook_never_settles_from_its_own_body_when_the_fetch_fails(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_secret' => 'whsec_1']);
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-W1', 'description' => 'أتعاب', 'amount' => 1150,
            'status' => InvoiceStatus::Due->value, 'tone' => 'b-amber', 'due_label' => '—', 'paid' => false,
        ]);
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([], 500)]);

        $this->postJson(route('webhooks.moyasar'), [
            'secret_token' => 'whsec_1',
            'type' => 'payment_paid',
            'data' => ['id' => 'pay_forged', 'status' => 'paid', 'amount' => 115000, 'currency' => 'SAR', 'metadata' => ['invoice_number' => 'INV-W1']],
        ])->assertStatus(502);

        $this->assertFalse((bool) $invoice->fresh()->paid);
    }

    public function test_production_seeding_skips_the_demo_accounts_and_demo_data(): void
    {
        $this->app['env'] = 'production';

        // `--force` كما يُشغَّل على الخادم (الإنتاج يطلب تأكيداً تفاعليّاً بدونه)
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        $this->assertGreaterThan(0, Permission::count());
        $this->assertSame(0, User::count());

        $this->artisan('db:seed', ['--class' => RichDemoSeeder::class, '--force' => true])->assertSuccessful();
        $this->assertSame(0, User::count());
    }

    public function test_sandbox_seeding_still_creates_the_four_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(4, User::whereIn('national_id', ['1000000001', '1000000002', '1000000003', '1000000004'])->count());
    }

    public function test_pdf_binary_paths_come_from_config(): void
    {
        config(['pdf.node_binary' => PHP_BINARY]);

        $this->assertSame(PHP_BINARY, PdfRenderer::resolveNodePath());
    }
}
