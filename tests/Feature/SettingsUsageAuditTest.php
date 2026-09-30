<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalDocument;
use App\Models\Setting;
use App\Models\User;
use App\Support\ExecFlow;
use App\Support\Finance\ZatcaQr;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * **تدقيق استعمال متغيّرات الإعدادات** (2026-09-30) — كلّ اختبارٍ هنا عيبٌ ثبت قبل الإصلاح: قيمةٌ تُقرأ حيّةً
 * وهي مجمَّدة، أو علاقةٌ بين إعدادين تقبل ما يُعطّل النظام، أو نصٌّ منقوش بدل الإعداد.
 */
class SettingsUsageAuditTest extends TestCase
{
    use RefreshDatabase;

    private function set(string $key, mixed $value): void
    {
        Setting::put($key, $value);
        SettingsRegistry::flush();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    // ── ١: نسبة ضريبة ملفّ التنفيذ مجمَّدة مع أتعابه ──

    public function test_an_execution_shows_the_vat_rate_its_fee_was_priced_at_not_todays(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-VAT-1', 'subject' => 'تنفيذ حكم', 'status' => 'عرض الخدمة',
            'tone' => 'b-amber', 'stage' => 5, 'amount' => 50000, 'fee' => 1000, 'vat' => 150, 'fee_mode' => 'fixed',
        ]);

        $this->set('vat_rate', 5);

        $this->assertSame(15, $exec->vatRate());
        $this->assertSame(15, $exec->toFlowCard()['vatRate']);
    }

    // ── ٢ + ٣ + ٤: علاقات بين الإعدادات تُرفض قبل الحفظ ──

    public function test_a_slot_longer_than_the_office_day_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.settings.update'), ['consult_day_start' => 9, 'consult_day_end' => 10, 'consult_slot_minutes' => 90])
            ->assertSessionHasErrors('consult_slot_minutes');

        $this->assertNull(Setting::get('consult_slot_minutes'));
    }

    public function test_the_installment_interval_must_exceed_the_first_due_days(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.settings.update'), ['installment_first_due_days' => 30, 'installment_interval_days' => 30])
            ->assertSessionHasErrors('installment_interval_days');

        $this->assertNull(Setting::get('installment_interval_days'));
    }

    public function test_autoclose_cannot_come_before_a_session_counts_as_missed(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.settings.update'), ['session_missed_after_minutes' => 120, 'consult_autoclose_minutes' => 60, 'meeting_autoclose_minutes' => 60])
            ->assertSessionHasErrors(['consult_autoclose_minutes', 'meeting_autoclose_minutes']);

        $this->assertNull(Setting::get('consult_autoclose_minutes'));
    }

    public function test_the_registry_defaults_satisfy_every_relation(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.settings.update'), collect(['consult_day_start', 'consult_day_end', 'consult_slot_minutes', 'installment_first_due_days',
                'installment_interval_days', 'session_missed_after_minutes', 'consult_autoclose_minutes', 'meeting_autoclose_minutes'])
                ->mapWithKeys(fn (string $k) => [$k => SettingsRegistry::int($k)])->all())
            ->assertSessionHasNoErrors();
    }

    // ── ٥: مهلة السداد بحسب تاريخ الإبلاغ لا يوم التسجيل ──

    public function test_the_pay_deadline_follows_the_notification_date_not_the_day_it_is_computed(): void
    {
        $this->set('exec_working_days_from', '2026-10-01');
        $this->set('exec_pay_days', 5);
        Carbon::setTestNow('2026-10-10 10:00');

        // أُبلغ الأحد 27 سبتمبر — قبل بدء أيّام العمل، فمهلته أيّامٌ تقويميّة ولو حُسبت بعدُ
        $this->assertSame('2026-10-02', ExecFlow::payDueAfter(Carbon::parse('2026-09-27 12:00'))->toDateString());
        // وأُبلغ الأحد 4 أكتوبر — بعده، فتُتخطّى الجمعة والسبت
        $this->assertSame('2026-10-11', ExecFlow::payDueAfter(Carbon::parse('2026-10-04 12:00'))->toDateString());

        Carbon::setTestNow();
    }

    // ── ٦: بيانات البائع في الفاتورة الضريبيّة مجمَّدة يوم الإصدار ──

    public function test_an_issued_invoice_keeps_the_seller_it_was_issued_under(): void
    {
        $this->set('office_name', 'مكتب الأمس');
        $this->set('office_vat_number', '300000000000003');
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-SELLER-1', 'amount' => 1150, 'subtotal' => 1000, 'vat_rate' => 15, 'vat_amount' => 150,
            'description' => 'أتعاب', 'status' => 'مستحقة', 'paid' => false, 'due_label' => 'عند الطلب', 'issued_at' => now(),
        ]);

        $this->set('office_name', 'مكتب اليوم');
        $this->set('office_vat_number', '311111111111113');
        $invoice->refresh();

        $this->assertSame('مكتب الأمس', $invoice->sellerName());
        $fields = ZatcaQr::fields($invoice);
        $this->assertSame('مكتب الأمس', $fields[ZatcaQr::TAG_SELLER]);
        $this->assertSame('300000000000003', $fields[ZatcaQr::TAG_VAT_NUMBER]);
    }

    public function test_a_draft_invoice_takes_the_seller_when_issued_not_before(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-SELLER-2', 'amount' => 1150, 'description' => 'أتعاب',
            'status' => 'مسودة', 'paid' => false, 'due_label' => 'عند الطلب',
        ]);
        $this->assertNull($invoice->seller_name);

        $this->set('office_name', 'مكتب يوم الإصدار');
        $invoice->update(['issued_at' => now()]);

        $this->assertSame('مكتب يوم الإصدار', $invoice->fresh()->seller_name);
    }

    // ── ٧: ترويسة المستندات القانونيّة من بيانات المكتب ──

    public function test_the_default_document_header_reads_the_office_settings(): void
    {
        $this->set('office_name', 'مكتب الاختبار للمحاماة');
        $this->set('office_phone', '011 222 3333');
        $this->set('office_email', 'info@office.test');
        $this->set('office_address', 'الرياض — حيّ الاختبار');

        $header = LegalDocument::defaultHeader();

        $this->assertSame('مكتب الاختبار للمحاماة', $header['officeName']);
        $this->assertSame('011 222 3333', $header['phone']);
        $this->assertSame('info@office.test', $header['email']);
        $this->assertSame('الرياض — حيّ الاختبار', $header['address']);
    }
}
