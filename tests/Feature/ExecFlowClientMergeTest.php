<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * التبويب الموحّد «التنفيذ» للعميل: يجمع التنفيذات القديمة (stage=null) والتدفّق (stage≠null)،
 * مع مستندات مطلوبة قابلة للرفع ومحادثة عميل↔مكتب — مطابقةً للتصميم المرجعي.
 */
class ExecFlowClientMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unified_tab_lists_legacy_and_flow_with_derived_stage(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $other = User::factory()->create(['role' => Role::Client]);

        // قديم قيد التنفيذ (stage=null) → مرحلة مشتقّة 8 (غير المغلق يبقى 8 قابلاً للإغلاق)
        Execution::create(['user_id' => $client->id, 'number' => 'EXE-OLD', 'subject' => 'تنفيذ حكم', 'status' => 'جارٍ', 'tone' => 'b-blue', 'last_action' => 'إجراء']);
        // قديم مغلق/مؤرشف → مرحلة مشتقّة 9
        Execution::create(['user_id' => $client->id, 'number' => 'EXE-DONE', 'subject' => 'تنفيذ سند', 'status' => 'مغلق', 'tone' => 'b-grey', 'last_action' => 'أُرشف']);
        // تدفّق فعليّ
        Execution::create(['user_id' => $client->id, 'number' => 'EXE-FLOW', 'subject' => 'تدفّق', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2]);
        // تنفيذ عميل آخر — يجب ألّا يظهر
        Execution::create(['user_id' => $other->id, 'number' => 'EXE-X', 'subject' => 'آخر', 'status' => 'جارٍ', 'tone' => 'b-blue']);

        $this->actingAs($client)->get(route('execs'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('execflow')
            ->where('role', 'client')
            ->has('execs', 3)
            ->where('execs', fn ($execs) => collect($execs)->firstWhere('id', 'EXE-OLD')['stage'] === 8
                && collect($execs)->firstWhere('id', 'EXE-DONE')['stage'] === 9
                && collect($execs)->firstWhere('id', 'EXE-DONE')['closed'] === true
                && collect($execs)->firstWhere('id', 'EXE-FLOW')['stage'] === 2
                && collect($execs)->doesntContain('id', 'EXE-X')));
    }

    public function test_lawyer_request_docs_creates_document_rows(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-D', 'subject' => 'تنفيذ', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2, 'assigned_lawyer_id' => $lawyer->id]);

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), ['action' => 'requestDocs'])->assertRedirect();

        $this->assertGreaterThan(0, $exec->documents()->count());
        $this->assertSame('مطلوب', $exec->documents()->first()->status);
    }

    public function test_client_uploads_requested_document(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-U', 'subject' => 'تنفيذ', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2]);
        $doc = $exec->documents()->create(['label' => 'السند التنفيذي', 'status' => 'مطلوب']);

        $this->actingAs($client)->post(route('exec-flow.documents.upload', [$exec, $doc]), [
            'file' => UploadedFile::fake()->create('sanad.pdf', 200, 'application/pdf'),
        ])->assertRedirect();

        $doc->refresh();
        $this->assertSame('مرفوع', $doc->status);
        $this->assertNotNull($doc->path);
        Storage::disk('local')->assertExists($doc->path);
    }

    public function test_upload_rejects_oversized_file(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-B', 'subject' => 'تنفيذ', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2]);
        $doc = $exec->documents()->create(['label' => 'الهوية', 'status' => 'مطلوب']);

        $this->actingAs($client)->post(route('exec-flow.documents.upload', [$exec, $doc]), [
            'file' => UploadedFile::fake()->create('big.pdf', 3000, 'application/pdf'), // > 2MB
        ])->assertSessionHasErrors('file');
        $this->assertSame('مطلوب', $doc->fresh()->status);
    }

    public function test_upload_guards_ownership_and_document_belonging(): void
    {
        Storage::fake('local');
        $me = User::factory()->create(['role' => Role::Client]);
        $other = User::factory()->create(['role' => Role::Client]);
        $execMe = Execution::create(['user_id' => $me->id, 'number' => 'EXE-ME', 'subject' => 'لي', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2]);
        $execOther = Execution::create(['user_id' => $other->id, 'number' => 'EXE-OT', 'subject' => 'آخر', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2]);
        $docOther = $execOther->documents()->create(['label' => 'x', 'status' => 'مطلوب']);
        $file = ['file' => UploadedFile::fake()->create('f.pdf', 10, 'application/pdf')];

        // مستند لا يخصّ العميل → 403 (ملكيّة الطلب)
        $this->actingAs($me)->post(route('exec-flow.documents.upload', [$execOther, $docOther]), $file)->assertForbidden();
        // مستند لا ينتمي للطلب الممرَّر → 404
        $this->actingAs($me)->post(route('exec-flow.documents.upload', [$execMe, $docOther]), $file)->assertNotFound();
    }
}
