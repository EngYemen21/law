<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تقرير الاستشارة PDF — يُصيَّر فعلياً عبر Browsershot (كروم مخفي حقيقي)، لا صورة/محاكاة.
 */
class ConsultReportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_downloads_real_pdf_report(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id,
            'ref' => 'CN-2026-9001',
            'subject' => 'نزاع تجاري',
            'specialty' => 'القضايا التجارية',
            'status' => 'جديدة',
            'session' => 'بانتظار الجلسة',
            'channel' => 'مرئية',
            'lawyer' => 'أ. سارة القحطاني',
            'when_label' => 'الاثنين 10 أغسطس · 11:00',
            'price' => 450,
            'vat' => 68,
            'total' => 518,
            'priced_at' => now(),
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($client)->get(route('consults.report', $consult));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('CN-2026-9001.pdf', (string) $response->headers->get('Content-Disposition'));
        // ملف PDF حقيقي (يبدأ بالتوقيع القياسي) — تأكيد أنه تصيير فعلي لا نص/صورة وهمية
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_other_client_cannot_download_foreign_report(): void
    {
        $owner = User::factory()->create(['role' => Role::Client]);
        $intruder = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $owner->id,
            'ref' => 'CN-2026-9002',
            'subject' => 'استشارة',
            'status' => 'جديدة',
            'session' => 'بانتظار الجلسة',
            'channel' => 'هاتفية',
            'lawyer' => 'أ. سارة القحطاني',
        ]);

        $this->actingAs($intruder)->get(route('consults.report', $consult))->assertForbidden();
    }
}
