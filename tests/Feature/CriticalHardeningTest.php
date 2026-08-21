<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\ExecutionDocument;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * تحصين البنود الحرجة (الدفعة ح): كل اختبار هنا يمثّل ثغرة كانت مفتوحة فعلاً.
 */
class CriticalHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ── ح1: تسجيل واحد محروس للمساعد (كان مسجّلاً مرتين فيُبطل الحارس ويُفشل route:cache) ──

    public function test_assistant_route_is_registered_once_and_guarded(): void
    {
        $named = collect(app('router')->getRoutes()->getRoutesByName())->keys()
            ->filter(fn ($n) => $n === 'lawyer.assistant');
        $this->assertCount(1, $named);

        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions([]); // بلا «المساعد القانوني»

        $this->actingAs($lawyer)->get('/lawyer/assistant')->assertRedirect();
    }

    public function test_lawyer_with_permission_still_reaches_assistant(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $this->actingAs($lawyer)->get('/lawyer/assistant')->assertOk();
    }

    // ── ح4: مرجع المساعد محروس بالإسناد (كان أي محامٍ يقرأ وقائع ملفّات زملائه) ──

    public function test_assistant_rejects_reference_assigned_to_another_lawyer(): void
    {
        $mine = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);

        $foreign = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-SEC-1', 'type' => 'تجاري',
            'assigned_lawyer_id' => $other->id, 'status' => 'قيد المعالجة', 'tone' => 'b-blue',
        ]);

        $this->actingAs($mine)->postJson(route('lawyer.assistant.generate'), [
            'kind' => 'analyze', 'docType' => 'تحليل', 'ref' => $foreign->number,
        ])->assertForbidden();
    }

    public function test_assistant_allows_reference_matching_no_record(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        // مرجع حرّ لا يطابق سجلاً: لا شيء يُقرأ منه، فلا سبب لمنعه
        $this->actingAs($lawyer)->postJson(route('lawyer.assistant.generate'), [
            'kind' => 'analyze', 'docType' => 'تحليل', 'ref' => 'SB-NOPE-1', 'context' => 'وقائع.',
        ])->assertOk();
    }

    public function test_assistant_accepts_own_reference(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-SEC-1', 'type' => 'تجاري',
            'assigned_lawyer_id' => $lawyer->id, 'status' => 'قيد التحضير', 'tone' => 'b-blue',
        ]);

        $this->actingAs($lawyer)->postJson(route('lawyer.assistant.generate'), [
            'kind' => 'analyze', 'docType' => 'تحليل', 'ref' => 'CASE-SEC-1',
        ])->assertOk();
    }

    // ── ح5: مستندات التنفيذ تتطلّب صلاحية الملفّات للطاقم، والعميل يبقى على مستنداته ──

    private function execWithDocument(User $client): array
    {
        $execution = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-SEC-1', 'subject' => 'تنفيذ',
            'status' => 'جديد', 'tone' => 'b-blue', 'last_action' => 'فتح',
        ]);
        $document = ExecutionDocument::create([
            'execution_id' => $execution->id, 'label' => 'صك الحكم',
            'status' => 'مرفوع', 'path' => 'executions/seed.pdf',
        ]);

        return [$execution, $document];
    }

    public function test_employee_without_case_permission_cannot_download_execution_document(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        [$execution, $document] = $this->execWithDocument($client);

        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions([]); // بلا «إدارة القضايا والأتعاب»

        $this->actingAs($employee)
            ->get(route('exec-flow.documents.download', [$execution, $document]))
            ->assertForbidden();
    }

    public function test_client_still_downloads_own_execution_document(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        [$execution, $document] = $this->execWithDocument($client);
        Storage::put($document->path, 'PDF');

        // العميل صاحب الملفّ لا يتأثّر بحارس صلاحية الطاقم المضاف
        $this->actingAs($client)
            ->get(route('exec-flow.documents.download', [$execution, $document]))
            ->assertOk();
    }

    public function test_missing_file_on_disk_returns_404_not_500(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        [$execution, $document] = $this->execWithDocument($client);

        // سجلّ بمسار لكن الملف غير موجود — كان يرمي 500 من طبقة نظام الملفّات
        $this->actingAs($client)
            ->get(route('exec-flow.documents.download', [$execution, $document]))
            ->assertNotFound();
    }

    // ── ح3: التصفير محصّن بعبارة تأكيد (وبحظر الإنتاج) ──

    public function test_reset_database_requires_explicit_confirmation(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.reset-database'))
            ->assertSessionHasErrors('confirm');
    }

    public function test_reset_database_runs_with_confirmation_outside_production(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.reset-database'), ['confirm' => 'RESET'])
            ->assertRedirect(route('admin.dashboard', absolute: false));
    }

    // ── ح6: الجوال عامل المصادقة الوحيد — شكل صالح وعدم تعارض مع (phone, role) ──

    public function test_profile_rejects_malformed_phone(): void
    {
        $user = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($user)->post(route('profile.update'), [
            'name' => 'اسم كامل', 'email' => $user->email, 'phone' => 'not-a-phone',
        ])->assertSessionHasErrors('phone');
    }

    public function test_profile_rejects_phone_taken_by_same_role(): void
    {
        $taken = User::factory()->create(['role' => Role::Client, 'phone' => '966500000001']);
        $user = User::factory()->create(['role' => Role::Client, 'phone' => '966500000002']);

        $this->actingAs($user)->post(route('profile.update'), [
            'name' => 'اسم كامل', 'email' => $user->email, 'phone' => $taken->phone,
        ])->assertSessionHasErrors('phone');
    }

    public function test_profile_accepts_valid_phone(): void
    {
        $user = User::factory()->create(['role' => Role::Client, 'phone' => '966500000003']);

        $this->actingAs($user)->post(route('profile.update'), [
            'name' => 'اسم كامل', 'email' => $user->email, 'phone' => '0551234567',
        ])->assertSessionHasNoErrors();
    }
}
