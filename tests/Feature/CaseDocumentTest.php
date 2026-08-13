<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CaseDocument;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\LegalAiService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * مستندات ملف القضية — رفع حقيقي من العميل والمحامي، تخزين ضمن مستندات القضية،
 * منع الرفع على المؤرشفة/المغلقة، تحقق الامتدادات، وعزل الوصول.
 */
class CaseDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function makeCase(User $client, string $status = 'منظورة', ?User $lawyer = null): LegalCase
    {
        return LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-2026-8001', 'type' => 'نزاع تجاري',
            'assigned_lawyer' => $lawyer?->name ?? 'أ. سارة', 'assigned_lawyer_id' => $lawyer?->id,
            'branch' => 'الفرع الرئيسي — جدة', 'status' => $status, 'tone' => 'b-blue', 'update_text' => '—',
        ]);
    }

    public function test_client_attaches_document_to_own_case(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->makeCase($client);

        $this->actingAs($client)->post(route('cases.attach', $case), [
            'file' => UploadedFile::fake()->create('عقد.pdf', 120, 'application/pdf'),
        ])->assertNoContent();

        $doc = CaseDocument::where('case_id', $case->id)->firstOrFail();
        $this->assertSame('عقد.pdf', $doc->name);
        $this->assertSame('client', $doc->uploaded_by);
        Storage::disk('local')->assertExists($doc->path);
        // ظهرت رسالة مستند في محادثة القضية + يظهر في حمولة العرض
        $this->assertTrue($case->messages()->where('who', 'client')->get()->contains(fn ($m) => str_contains($m->body, 'عقد.pdf')));
        $this->actingAs($client)->get(route('cases.show', $case))
            ->assertOk()->assertInertia(fn ($p) => $p->has('documents', 1));
    }

    public function test_assigned_lawyer_attaches_document(): void
    {
        Storage::fake('local');
        $this->seed(PermissionSeeder::class);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $lawyer->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $case = $this->makeCase($client, 'منظورة', $lawyer);

        $this->actingAs($lawyer)->post(route('lawyer.cases.attach', $case), [
            'file' => UploadedFile::fake()->create('مذكرة.pdf', 90, 'application/pdf'),
        ])->assertRedirect();

        $doc = CaseDocument::where('case_id', $case->id)->firstOrFail();
        $this->assertSame('lawyer', $doc->uploaded_by);
    }

    public function test_client_cannot_attach_to_archived_case(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->makeCase($client, 'مؤرشفة');

        $this->actingAs($client)->post(route('cases.attach', $case), [
            'file' => UploadedFile::fake()->create('ملف.pdf', 50, 'application/pdf'),
        ])->assertStatus(422);

        $this->assertSame(0, CaseDocument::where('case_id', $case->id)->count());
    }

    public function test_rejects_disallowed_extension(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->makeCase($client);

        $this->actingAs($client)->post(route('cases.attach', $case), [
            'file' => UploadedFile::fake()->create('برنامج.exe', 40, 'application/octet-stream'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, CaseDocument::where('case_id', $case->id)->count());
    }

    public function test_uploaded_document_is_analyzed_and_summarized(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->makeCase($client);
        // تزييف التحليل الذكي (المفاتيح فارغة في بيئة الاختبار) — يُشغَّل تزامنياً عبر الطابور المتزامن
        $this->partialMock(LegalAiService::class, function ($mock) {
            $mock->shouldReceive('analyzeCaseDocument')->andReturn(['doc_type' => 'عقد توريد', 'summary' => 'عقد توريد بين الطرفين.']);
        });

        $this->actingAs($client)->post(route('cases.attach', $case), [
            'file' => UploadedFile::fake()->create('عقد.pdf', 120, 'application/pdf'),
        ])->assertNoContent();

        $doc = CaseDocument::where('case_id', $case->id)->firstOrFail();
        $this->assertSame('محلَّل', $doc->status);
        $this->assertSame('عقد توريد', $doc->doc_type);
        $this->assertNotEmpty($doc->summary);
        // رسالة ملخّص التحليل ظهرت في محادثة القضية
        $this->assertTrue($case->messages()->where('role', 'تحليل المستند')->exists());
    }

    public function test_non_owner_cannot_attach(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $other = User::factory()->create(['role' => Role::Client]);
        $case = $this->makeCase($client);

        $this->actingAs($other)->post(route('cases.attach', $case), [
            'file' => UploadedFile::fake()->create('ملف.pdf', 50, 'application/pdf'),
        ])->assertForbidden();
    }
}
