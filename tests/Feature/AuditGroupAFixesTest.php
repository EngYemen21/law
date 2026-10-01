<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalDocument;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Finance\Money;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **المجموعة (أ) من تدقيق P4/P5** (قرار المالك 2026-09-30) — كلٌّ ثبت قبل إصلاحه:
 * أتعاب التنفيذ بلا حدٍّ أعلى (فاتورةٌ فوق سعة عمودها ⇒ 500)، ومحضر الاستشارة يُستورد للمحرّر بتطابق
 * **الاسم**، وربط المستند بتذكرةٍ ليست للمحامي، والمحرّر وزرّ الاعتماد يبقيان بعد اعتماد المحامي الملخّص.
 */
class AuditGroupAFixesTest extends TestCase
{
    use RefreshDatabase;

    /** محامٍ يملك صلاحيّات المحرّر — كي يقيس الاختبار الحارس لا وسيط الصلاحيّة. */
    private function editorLawyer(string $name = 'محامٍ'): User
    {
        $this->seed(PermissionSeeder::class);
        $u = User::factory()->create(['role' => Role::Lawyer, 'name' => $name, 'status' => 'active']);
        $u->syncPermissions(Permission::whereIn('name', [Permissions::LEGAL_ASSISTANT, Permissions::APPROVE_DOCUMENTS])->get());

        return $u;
    }

    private function execReadyToPrice(): Execution
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        return Execution::create(['user_id' => $client->id, 'number' => 'EXE-GA-'.uniqid(), 'subject' => 'تسعير', 'status' => 'قيد الدراسة', 'tone' => 'b-blue',
            'stage' => 2, 'amount' => 80000, 'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name]);
    }

    public function test_one_fee_ceiling_for_cases_and_executions(): void
    {
        $this->assertSame(10000000, Money::MAX_FEE);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $exec = $this->execReadyToPrice();
        $this->actingAs($admin)->post(route('exec-flow.act', $exec), [
            'action' => 'setFee', 'fee' => Money::MAX_FEE + 1, 'duration' => '30 يوم', 'payMethod' => 'دفعة واحدة',
        ])->assertStatus(422);
        $this->assertNotSame(Money::MAX_FEE + 1, (int) $exec->fresh()->fee, 'لا تسعير فوق الحدّ');
        $this->assertSame(2, (int) $exec->fresh()->stage);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), [
            'action' => 'setFee', 'fee' => Money::MAX_FEE, 'duration' => '30 يوم', 'payMethod' => 'دفعة واحدة',
        ])->assertRedirect();
        $this->assertSame(Money::MAX_FEE, (int) $exec->fresh()->fee);

        $pending = $this->execReadyToPrice();
        $this->actingAs($admin)->post(route('exec-flow.act', $pending), ['action' => 'approveFee', 'fee' => Money::MAX_FEE + 1])->assertStatus(422);
        $this->assertSame(2, (int) $pending->fresh()->stage);

        $controller = (string) file_get_contents(app_path('Http/Controllers/Admin/CaseController.php'));
        $this->assertStringContainsString("'max:'.Money::MAX_FEE", $controller);
        $this->assertStringNotContainsString('max:10000000', $controller);
    }

    /** محضر استشارةٍ لمحامٍ آخر لا يُستورد لمن طابق اسمُه الاسمَ المكتوب عليها — العزل بالإسناد وحده. */
    public function test_the_editor_imports_a_consult_summary_by_assignment_only(): void
    {
        $owner = $this->editorLawyer('أ. محمد');
        $namesake = $this->editorLawyer('أ. محمد');
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create(['user_id' => $client->id, 'ref' => 'CN-GA-'.uniqid(), 'subject' => 'نزاع', 'type' => 'استشارة', 'channel' => 'مرئية',
            'status' => 'مكتملة', 'session' => 'منتهية', 'tone' => 'b-green', 'lawyer' => 'أ. محمد', 'assigned_lawyer_id' => $owner->id, 'summary' => 'ملخّص سرّيّ للموكّل']);

        $this->actingAs($namesake)->get("/lawyer/editor/create?importType=session_summary&id={$consult->id}")
            ->assertInertia(fn ($page) => $page->where('incomingDraft', fn ($d) => ! str_contains((string) $d, 'ملخّص سرّيّ')));
        $ids = collect($this->actingAs($namesake)->getJson('/lawyer/editor/importables')->json())->flatten()->all();
        $this->assertNotContains("session_summary_{$consult->id}", $ids);

        $this->actingAs($owner)->get("/lawyer/editor/create?importType=session_summary&id={$consult->id}")
            ->assertInertia(fn ($page) => $page->where('incomingDraft', fn ($d) => str_contains((string) $d, 'ملخّص سرّيّ')));
    }

    /** ربط المستند بتذكرةٍ حكمُ الإسناد كالقضيّة — كان `exists` وحده. */
    public function test_the_editor_links_a_document_only_to_an_assigned_ticket(): void
    {
        $lawyer = $this->editorLawyer();
        $other = $this->editorLawyer();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $mine = Ticket::create(['user_id' => $client->id, 'number' => 'SB-GA-'.uniqid(), 'type' => 'نزاع', 'status' => 'قيد التحليل', 'tone' => 'b-blue', 'assigned_lawyer_id' => $lawyer->id]);
        $theirs = Ticket::create(['user_id' => $client->id, 'number' => 'SB-GA-'.uniqid(), 'type' => 'نزاع', 'status' => 'قيد التحليل', 'tone' => 'b-blue', 'assigned_lawyer_id' => $other->id]);
        $body = fn (int $ticketId) => ['title' => 'مذكّرة', 'type' => 'memo', 'content_html' => '<p>نصّ</p>', 'ticket_id' => $ticketId];

        $this->actingAs($lawyer)->post('/lawyer/editor', $body($theirs->id))->assertForbidden();
        $this->assertSame(0, LegalDocument::count());

        $this->actingAs($lawyer)->post('/lawyer/editor', $body($mine->id))->assertRedirect();
        $doc = LegalDocument::sole();
        // التعديل لا يقبل `ticket_id` أصلاً — فلا يتغيّر الربط
        $this->actingAs($lawyer)->put("/lawyer/editor/{$doc->id}", $body($theirs->id));
        $this->assertSame($mine->id, (int) $doc->fresh()->ticket_id);

        $this->actingAs($admin)->post('/admin/editor', $body($theirs->id))->assertRedirect();
    }

    /** بعد اعتماد المحامي: لا محرّر ولا زرّ يردّهما الخادم، والزرّ يقول ما يفعله لكلّ دور. */
    public function test_the_consult_page_locks_the_summary_after_the_lawyer_approves(): void
    {
        $src = (string) file_get_contents(resource_path('js/lib/consult-ui.tsx'));
        $this->assertStringContainsString('c.summaryLawyerApproved', $src);
        $this->assertStringContainsString("'اعتماد ورفع للإدارة'", $src);
    }
}
