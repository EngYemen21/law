<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * طباعة عرض/فاتورة خدمة التنفيذ (PDF) — يُصيَّر فعلياً عبر Browsershot (كروم مخفي حقيقي)، لا صورة/محاكاة.
 * نظير ConsultReportPdfTest، ويستبدل نافذة window.print() المحليّة السابقة.
 */
class ExecOfferPdfTest extends TestCase
{
    use RefreshDatabase;

    private function execFor(User $client, array $attrs = []): Execution
    {
        return Execution::create(array_merge([
            'user_id' => $client->id,
            'number' => 'EXE-2026-0001',
            'subject' => 'تنفيذ حكم مالي',
            'status' => 'عرض الخدمة',
            'tone' => 'b-blue',
            'stage' => 5,
            'sanad' => 'حكم قضائي',
            'fee' => 2500,
            'vat' => 375,
            'fee_approved' => true,
        ], $attrs));
    }

    public function test_client_downloads_real_offer_pdf(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->execFor($client);

        $response = $this->actingAs($client)->get(route('exec-flow.offer.pdf', $exec));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('EXE-2026-0001.pdf', (string) $response->headers->get('Content-Disposition'));
        // ملف PDF حقيقي (يبدأ بالتوقيع القياسي) — تأكيد أنه تصيير فعلي لا نص/صورة وهمية
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_assigned_lawyer_downloads_offer_pdf(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $exec = $this->execFor($client, ['assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name]);

        $this->actingAs($lawyer)->get(route('exec-flow.offer.pdf', $exec))->assertOk();
    }

    public function test_non_owner_client_cannot_download(): void
    {
        $owner = User::factory()->create(['role' => Role::Client]);
        $intruder = User::factory()->create(['role' => Role::Client]);
        $exec = $this->execFor($owner);

        $this->actingAs($intruder)->get(route('exec-flow.offer.pdf', $exec))->assertForbidden();
    }

    public function test_unassigned_lawyer_cannot_download_another_lawyers_offer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $owner = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);
        $exec = $this->execFor($client, ['assigned_lawyer_id' => $owner->id]);

        $this->actingAs($other)->get(route('exec-flow.offer.pdf', $exec))->assertForbidden();
    }

    public function test_returns_422_when_no_fee_set_yet(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->execFor($client, ['fee' => 0, 'vat' => 0, 'fee_approved' => false, 'stage' => 2]);

        $this->actingAs($client)->get(route('exec-flow.offer.pdf', $exec))->assertStatus(422);
    }
}
