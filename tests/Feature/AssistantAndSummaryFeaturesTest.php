<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AssistantAndSummaryFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_assistant_generate_returns_legal_draft(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $lawyer->syncPermissions(Permission::all());

        $response = $this->actingAs($lawyer)->postJson('/lawyer/assistant/generate', [
            'kind' => 'reply_memo',
            'docType' => 'مذكرة رد وجوابية',
            'context' => 'وقائع النزاع حول عقد توريد مواد غذائية وإخلال المشتري بالسداد.',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['draft']);
        $this->assertNotEmpty($response->json('draft'));
    }

    public function test_generate_najiz_draft_for_ticket(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'خالد الشمري', 'branch' => 'فرع الرياض']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المستشار فهد', 'branch' => 'فرع الرياض']);
        $lawyer->syncPermissions(Permission::all());

        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'TKT-TEST-NAJIZ',
            'type' => 'نزاع تجاري',
            'subject' => 'مطالبة بمستحقات توريد بضاعة',
            'details' => 'قام الموكل بتوريد بضاعة بقيمة 150 ألف ريال وامتنع المدعى عليه عن السداد.',
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
            'branch' => 'فرع الرياض',
            'status' => 'قيد الدراسة',
            'tone' => 'b-blue',
        ]);

        TicketSummary::create([
            'ticket_id' => $ticket->id,
            'case_summary' => 'نزاع تجاري مالي ناشئ عن عقد توريد.',
            'attachments_summary' => 'فواتير التوريد وسندات الاستلام.',
            'facts' => "• توريد البضاعة بتاريخ 2026/01/10.\n• امتناع المشتري عن الوفاء.",
            'key_points' => 'استحقاق المطالبة استناداً لنظام المعاملات المدنية ونظام الإثبات.',
            'status' => 'pending',
            'result_status' => 'pending',
            'ai_generated' => true,
        ]);

        $response = $this->actingAs($lawyer)->postJson("/lawyer/summary/{$ticket->number}/najiz");

        $response->assertOk();
        $response->assertJsonStructure(['draft']);
        $draft = $response->json('draft');
        $this->assertStringContainsString('المملكة العربية السعودية', $draft);
        $this->assertStringContainsString('ناجز', $draft);
    }

    public function test_print_summary_html_report(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'سعد القحطاني', 'branch' => 'فرع الرياض']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المستشار عبد العزيز', 'branch' => 'فرع الرياض']);
        $lawyer->syncPermissions(Permission::all());

        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'TKT-TEST-PRINT',
            'type' => 'استشارة عقارية',
            'subject' => 'نزاع حول عقد إيجار تجاري',
            'details' => 'تفاصيل الاستشارة العقارية',
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
            'branch' => 'فرع الرياض',
            'status' => 'قيد الدراسة',
            'tone' => 'b-blue',
        ]);

        TicketSummary::create([
            'ticket_id' => $ticket->id,
            'case_summary' => 'ملخص النزاع الإيجاري.',
            'attachments_summary' => 'عقد الإيجار الموحد.',
            'facts' => 'إخلال المؤجر بتمكين المستأجر.',
            'key_points' => 'الرأي القانوني بالفسخ والتعويض.',
            'status' => 'approved',
            'result_status' => 'pending',
            'ai_generated' => true,
        ]);

        $response = $this->actingAs($lawyer)->get("/lawyer/summary/{$ticket->number}/print");

        $response->assertOk();
        $response->assertSee('النظام الإداري لمكاتب المحاماة');
        $response->assertSee('TKT-TEST-PRINT');
    }
}
