<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\User;
use App\Services\LegalAiService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ExecGuardsAndStageThreeDocsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function staff(Role $role): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $user->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب', 'إجراءات المحكمة والجلسات'])->get());

        return $user;
    }

    // ── 1. فحص توافق حراس دالة refer مع المحرك ──

    public function test_refer_guard_rejects_stage_two_cleanly_without_engine_crash(): void
    {
        $admin = $this->staff(Role::Admin);
        $client = User::factory()->create(['role' => Role::Client]);

        $exec = Execution::create([
            'user_id' => $client->id,
            'number' => 'EXE-REF-2',
            'subject' => 'تنفيذ سند',
            'sanad' => 'سند لأمر',
            'amount' => 50000,
            'status' => 'قيد الدراسة',
            'tone' => 'b-blue',
            'stage' => 2,
        ]);

        // يجب أن يُرفض من حارس ExecService مباشرة دون أن يرمي TransitionDenied غير معالج
        $this->actingAs($admin)
            ->post(route('exec-flow.act', $exec), ['action' => 'refer'])
            ->assertSessionHasErrors('stage');

        $this->assertSame(2, (int) $exec->fresh()->stage);
    }

    public function test_refer_guard_allows_stage_one_and_transitions_to_stage_two(): void
    {
        $admin = $this->staff(Role::Admin);
        $client = User::factory()->create(['role' => Role::Client]);

        $exec = Execution::create([
            'user_id' => $client->id,
            'number' => 'EXE-REF-1',
            'subject' => 'تنفيذ سند',
            'sanad' => 'سند لأمر',
            'amount' => 50000,
            'status' => 'تحليل ذكي',
            'tone' => 'b-blue',
            'stage' => 1,
        ]);

        $this->actingAs($admin)
            ->post(route('exec-flow.act', $exec), ['action' => 'refer'])
            ->assertRedirect();

        $this->assertSame(2, (int) $exec->fresh()->stage);
        $this->assertSame('قيد الدراسة', $exec->fresh()->status);
    }

    // ── 2. فحص إمكانية طلب المستندات في المرحلة 3 (تحديد الأتعاب) ──

    public function test_request_docs_is_allowed_in_stage_three(): void
    {
        $lawyer = $this->staff(Role::Lawyer);
        $client = User::factory()->create(['role' => Role::Client]);

        $exec = Execution::create([
            'user_id' => $client->id,
            'number' => 'EXE-ST3-DOCS',
            'subject' => 'تنفيذ سند محال من تذكرة',
            'sanad' => 'سند لأمر',
            'amount' => 90000,
            'status' => 'تحديد الأتعاب',
            'tone' => 'b-amber',
            'stage' => 3,
            'decision' => 'مقبول',
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
        ]);

        $this->actingAs($lawyer)
            ->post(route('exec-flow.act', $exec), ['action' => 'requestDocs'])
            ->assertRedirect();

        $exec->refresh();
        $this->assertSame(3, (int) $exec->stage);
        $this->assertGreaterThan(0, $exec->documents()->where('status', 'مطلوب')->count());
    }

    public function test_request_docs_is_refused_in_stage_four_and_above(): void
    {
        $lawyer = $this->staff(Role::Lawyer);
        $client = User::factory()->create(['role' => Role::Client]);

        $exec = Execution::create([
            'user_id' => $client->id,
            'number' => 'EXE-ST4-DOCS',
            'subject' => 'تنفيذ سند',
            'sanad' => 'سند لأمر',
            'amount' => 90000,
            'status' => 'اعتماد الإدارة',
            'tone' => 'b-amber',
            'stage' => 4,
            'fee' => 5000,
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
        ]);

        $this->actingAs($lawyer)
            ->post(route('exec-flow.act', $exec), ['action' => 'requestDocs'])
            ->assertSessionHasErrors('stage');
    }

    // ── 3. فحص قراءة الذكاء الاصطناعي للمستندات في التحليل ──

    public function test_ai_analysis_reads_ticket_documents_for_execution_from_ticket(): void
    {
        Storage::fake('local');

        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'TK-AI-DOC',
            'type' => 'تنفيذ',
            'subject' => 'تنفيذ كمبيالة',
        ]);

        $file = UploadedFile::fake()->create('promissory.pdf', 300, 'application/pdf');
        $storedPath = $file->storeAs("ticket-docs/{$ticket->id}", 'promissory.pdf', 'local');

        TicketDocument::create([
            'ticket_id' => $ticket->id,
            'name' => 'كمبيالة_بنكية.pdf',
            'path' => $storedPath,
            'mime' => 'application/pdf',
            'size' => 307200,
            'status' => 'مرتبط',
            'doc_type' => 'سند لأمر',
            'summary' => 'كمبيالة بنكية بقيمة 70 ألف ريال متضمنة توقيع المدين.',
        ]);

        $exec = Execution::create([
            'user_id' => $client->id,
            'ticket_id' => $ticket->id,
            'number' => 'EXE-AI-1',
            'subject' => 'تنفيذ كمبيالة',
            'sanad' => 'سند لأمر',
            'amount' => 70000,
            'status' => 'تحليل ذكي',
            'stage' => 1,
        ]);

        $ai = app(LegalAiService::class);
        $result = $ai->analyzeExecution($exec);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('source', $result);
    }
}
