<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\ConsultBooking;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **التسعير لكلّ استشارةٍ على حدة، والضريبة من «الإعدادات»** (قرار المالك 2026-09-29).
 *
 * حُذف تبويب «أسعار الاستشارات» (`/admin/prices`): كان يحفظ أسعاراً «مقترحة» تُكتب على كلّ طلبٍ جديد
 * وتُعبّأ في نافذة التسعير، ونسبةَ الضريبة. فالطلب اليوم بلا سعرٍ حتى يسعّره المسعّر، والضريبة حقلٌ في
 * «الإعدادات ← الفواتير والسداد» بقارئها الواحد `Setting::vatRate()`.
 */
class ConsultPricingIsPerRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_prices_tab_is_gone(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->get('/admin/prices')->assertNotFound();
        $this->assertStringNotContainsString("'/admin/prices'", (string) file_get_contents(resource_path('js/lib/data.ts')));
        $this->assertFileDoesNotExist(resource_path('js/pages/admin/prices.tsx'));
        $this->assertFalse(method_exists(Setting::class, 'consultPrices'), 'الأسعار المقترحة عادت');
    }

    public function test_vat_rate_is_a_billing_setting_read_by_the_single_reader(): void
    {
        $field = SettingsRegistry::all()['vat_rate'];
        $this->assertSame('billing', $field['group']);
        $this->assertSame([0, 100, 15], [$field['min'], $field['max'], $field['default']]);
        $this->assertSame(15, Setting::vatRate());

        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.settings.update'), ['vat_rate' => 5])->assertRedirect();
        $this->assertSame(5, Setting::vatRate());
        $this->actingAs($admin)->post(route('admin.settings.update'), ['vat_rate' => 150])->assertSessionHasErrors('vat_rate');
        $this->assertSame(5, Setting::vatRate());
    }

    public function test_a_new_request_has_no_price_until_priced_with_the_settings_vat(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $staff = User::factory()->create(['role' => Role::Admin]);

        $consult = ConsultBooking::request($client, ['type' => 'video']);
        $this->assertSame([0, 0, 0], [(int) $consult->price, (int) $consult->vat, (int) $consult->total]);
        $this->assertFalse($consult->toCard()['priced']);

        Setting::put('vat_rate', 10);
        ConsultBooking::setPrice($consult->fresh(), 500, $staff);
        $priced = $consult->fresh();
        $this->assertSame([500, 50, 550], [(int) $priced->price, (int) $priced->vat, (int) $priced->total]);
        $this->assertTrue($priced->toCard()['priced']);
    }

    public function test_no_screen_suggests_a_price(): void
    {
        foreach (['admin/consults.tsx', 'admin/consult-requests.tsx'] as $page) {
            $this->assertStringNotContainsString('suggestedPrices', (string) file_get_contents(resource_path("js/pages/{$page}")), $page);
        }
    }
}
