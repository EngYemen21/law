<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalSource;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Services\Ai\LegalClaims;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * صحيفة ناجز: الوسم يصل الشاشة، لا يقف عند الخادم.
 *
 * صارت `najiz.statement` تُطابق استشهادها بقاعدة المصادر وتُعيد `verdict` و`source`،
 * لكن الواجهة كانت تقرأ `data.draft` وحده — فصحيفةٌ حكمها `unsupported` تُعرض على
 * المحامي كأيّ مسودّة سليمة. هذه الاختبارات تحرس **عقد الاستجابة** الذي تعتمد عليه
 * لافتة الشاشة: لو سقط حقلٌ منه لعادت الصحيفة بلا وسم صامتةً.
 */
class NajizDraftSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function source(string $ref): void
    {
        LegalSource::create([
            'ref' => $ref,
            'title' => 'التزامات المورّد',
            'system_name' => 'نظام تجريبيّ للاختبار',
            'article_no' => '12',
            'domain' => 'تجاري',
            'jurisdiction' => 'السعودية',
            'text' => 'يلتزم المورّد بتسليم البضاعة في الموعد المتفق عليه، وللمشتري فسخ العقد والتعويض عند التأخّر.',
            'version' => '1',
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'source_owner' => 'الفريق القانونيّ للمكتب',
            'legal_review_at' => '2026-01-01',
            'usage_scope' => 'مسودات داخليّة',
            'status' => LegalSource::STATUS_APPROVED,
        ]);
    }

    /** @return array{0:User, 1:Ticket} */
    private function fileFor(string $reply): array
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $reply]]]]],
        ], 200)]);

        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions(Permission::all());

        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-NJZ-'.uniqid(),
            'type' => 'نزاع تجاري',
            'department' => 'تجاري',
            'subject' => 'فسخ عقد توريد والتعويض',
            'details' => 'تأخّر المورّد عن التسليم.',
            'status' => 'قيد الدراسة',
            'tone' => 'b-blue',
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
        ]);
        TicketSummary::create([
            'ticket_id' => $ticket->id,
            'case_summary' => 'مطالبة بفسخ عقد توريد.',
            'facts' => 'لم تُسلَّم البضاعة رغم الإنذار.',
            'key_points' => 'الأساس النظاميّ للفسخ والتعويض.',
            'attachments_summary' => 'عقد التوريد.',
            'status' => 'approved',
            'result_status' => 'pending',
            'ai_generated' => true,
        ]);

        return [$lawyer, $ticket];
    }

    /** الاستجابة تحمل الوسم — عقدٌ تعتمد عليه لافتة الشاشة. */
    public function test_the_endpoint_carries_the_verdict_to_the_client(): void
    {
        $this->source('LS-NJZ-OK');
        [$lawyer, $ticket] = $this->fileFor(json_encode([
            'draft' => 'صحيفة دعوى.',
            'claims' => [['text' => 'ادّعاء.', 'source_id' => 'LS-NJZ-OK', 'source_excerpt' => 'نصّ']],
            'unsupported_claims' => [],
        ], JSON_UNESCAPED_UNICODE));

        $response = $this->actingAs($lawyer)
            ->postJson("/lawyer/summary/{$ticket->number}/najiz");

        $response->assertOk()
            ->assertJsonStructure(['draft', 'source', 'sourceLabel', 'verdict']);
        $this->assertSame(LegalClaims::SUPPORTED, $response->json('verdict'));
        $this->assertSame('ai_success', $response->json('source'));
    }

    /** ومعرّفٌ لا يقابله صفٌّ معتمد يُخرج الصحيفة موسومةً `unsupported`. */
    public function test_an_unsupported_statement_is_not_returned_as_a_clean_draft(): void
    {
        $this->source('LS-NJZ-OK');
        [$lawyer, $ticket] = $this->fileFor(json_encode([
            'draft' => 'صحيفة دعوى.',
            'claims' => [['text' => 'ادّعاء.', 'source_id' => 'LS-CIVIL-9999', 'source_excerpt' => 'متخيَّل']],
            'unsupported_claims' => [],
        ], JSON_UNESCAPED_UNICODE));

        $response = $this->actingAs($lawyer)
            ->postJson("/lawyer/summary/{$ticket->number}/najiz");

        $response->assertOk();
        $this->assertSame(LegalClaims::UNSUPPORTED, $response->json('verdict'));
        $this->assertSame('manual_required', $response->json('source'), 'لا تُوسم نجاحاً');
        $this->assertStringContainsString('بلا سندٍ مُتحقَّق', (string) $response->json('draft'));
        $this->assertStringNotContainsString('LS-CIVIL-9999', (string) $response->json('draft'));
    }

    /** والوسم لا يُقدَّم فارغاً: `sourceLabel` نصٌّ يُعرض للمحامي. */
    public function test_the_source_label_is_human_readable(): void
    {
        $this->source('LS-NJZ-OK');
        [$lawyer, $ticket] = $this->fileFor('نصّ حرّ ليس JSON.');

        $label = $this->actingAs($lawyer)
            ->postJson("/lawyer/summary/{$ticket->number}/najiz")
            ->json('sourceLabel');

        $this->assertNotEmpty($label);
        $this->assertIsString($label);
    }
}
