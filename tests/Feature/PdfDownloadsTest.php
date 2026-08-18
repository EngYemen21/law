<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\User;
use App\Support\NativePdf;
use App\Support\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ضمانة تنزيلات PDF: كل نقاط التحميل تعيد ملف %PDF حقيقياً بترويسة application/pdf —
 * لا HTML أبداً (كان تعليق كروم أطول من max_execution_time يسقط PHP فيتنزّل card.html).
 */
class PdfDownloadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // حتمية الاختبار: المحرك الاحتياطي المضمون (بلا Node/Chrome) — العقد واحد أياً كان المحرك
        config()->set('pdf.engine', 'native');
    }

    private function assertPdfResponse($response, string $filename): void
    {
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString($filename, (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_appointment_card_downloads_as_real_pdf(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $appointment = Appointment::create([
            'user_id' => $client->id,
            'ext_id' => 'APT-2026-9001',
            'type' => 'استشارة حضورية',
            'ico' => 'office',
            'lawyer' => 'أ. سارة القحطاني',
            'day' => '2026-08-20',
            'time' => '10:00 ص',
            'branch' => 'الرياض — حي العليا',
            'status' => 'مؤكد',
            'tone' => 'b-green',
        ]);

        $response = $this->actingAs($client)->get("/appointments/{$appointment->ext_id}/card.pdf");

        $this->assertPdfResponse($response, 'APT-2026-9001.pdf');
    }

    public function test_consult_report_downloads_as_real_pdf(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id,
            'ref' => 'CN-2026-9100',
            'subject' => 'نزاع تجاري',
            'channel' => 'مرئية',
            'lawyer' => 'أ. سارة',
            'day' => 'الأحد',
            'time' => '10 ص',
            'when_label' => 'الأحد 10 ص',
            'price' => 450, 'vat' => 68, 'total' => 518,
            'status' => 'بانتظار السداد',
            'session' => 'بانتظار الجلسة',
        ]);

        $response = $this->actingAs($client)->get("/consults/{$consult->id}/report.pdf");

        $this->assertPdfResponse($response, 'CN-2026-9100.pdf');
    }

    public function test_invoice_downloads_as_real_pdf(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id,
            'number' => 'INV-2026-9200',
            'description' => 'أتعاب استشارة قانونية',
            'amount' => 518,
            'status' => 'مستحقة',
            'tone' => 'b-amber',
            'due_label' => 'خلال 3 أيام',
            'paid' => false,
        ]);

        $response = $this->actingAs($client)->get("/invoices/{$invoice->number}/pdf");

        $this->assertPdfResponse($response, 'INV-2026-9200.pdf');
    }

    public function test_generate_pdf_binary_always_returns_pdf_even_in_auto_mode(): void
    {
        // العقد الصلب: أياً كان المحرك المتاح على الجهاز (كروم/احتياطي) الناتج %PDF دائماً
        config()->set('pdf.engine', 'auto');
        config()->set('pdf.timeout', 10);

        $pdf = PdfRenderer::generatePdfBinary('<html dir="rtl"><body><h1>بطاقة موعد تجريبية</h1></body></html>', 'guarantee.pdf');

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_native_fallback_builds_valid_pdf(): void
    {
        $pdf = NativePdf::build('<div><h2>تقرير رسمي</h2><p>محتوى تجريبي</p></div>', 'native.pdf');

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
    }
}
