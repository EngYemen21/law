<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحة الإدارة العليا — التبويب الموحّد للتنفيذ: عرض الكلّ + تسعير مباشر + مراجعة مستندات +
 * محادثة المكتب + إغلاق التنفيذات القديمة عبر المرحلة المشتقّة.
 */
class AdminExecFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    public function test_admin_unified_tab_shows_all_executions(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        Execution::create(['user_id' => $client->id, 'number' => 'EXE-LEGACY', 'subject' => 'قديم', 'status' => 'جارٍ', 'tone' => 'b-blue']); // stage=null
        Execution::create(['user_id' => $client->id, 'number' => 'EXE-FLOW', 'subject' => 'تدفّق', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2]);

        $this->actingAs($this->admin())->get(route('admin.execs'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('execflow')
            ->where('role', 'admin')
            ->has('execs', 2));
    }

    /**
     * التسعير المباشر — **بعد إسناد الملفّ**. عقدٌ تغيّر عمداً: كان يُقبل على ملفٍّ
     * `assigned_lawyer_id = null` فيصل العميلَ عرضٌ بمدّةٍ ومبلغٍ على ملفٍّ لا محاميَ له
     * ولا من يُسأل عن تقديرهما. والإسناد صار إجراءً في الموزّع نفسه (`assignLawyer`).
     */
    public function test_admin_sets_and_approves_fee_directly(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-P', 'subject' => 'تسعير', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2, 'amount' => 80000]);
        $admin = $this->admin();

        // بلا محامٍ: مردودٌ برسالةٍ صريحة
        $this->actingAs($admin)->post(route('exec-flow.act', $exec), [
            'action' => 'setFee', 'fee' => 6000, 'duration' => '30-45 يوم', 'payMethod' => 'دفعة واحدة',
        ])->assertStatus(422);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), ['action' => 'assignLawyer', 'lawyer_id' => $lawyer->id])->assertRedirect();

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), [
            'action' => 'setFee', 'fee' => 6000, 'duration' => '30-45 يوم', 'payMethod' => 'دفعة واحدة',
        ])->assertRedirect();

        $exec->refresh();
        $this->assertSame(5, $exec->stage);           // اعتماد مباشر → عرض الخدمة
        $this->assertTrue((bool) $exec->fee_approved);
        $this->assertSame(6000, $exec->fee);
        $this->assertSame(900, $exec->vat);            // 15%
    }

    public function test_admin_closes_legacy_execution_via_flow(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-OLD', 'subject' => 'قديم', 'status' => 'جارٍ', 'tone' => 'b-blue']); // stage=null وحالةٌ غير مغلقة → مشتقّة 8

        $this->actingAs($this->admin())->post(route('exec-flow.act', $exec), ['action' => 'close'])->assertRedirect();
        $this->assertSame('مغلق', $exec->fresh()->status);
        $this->assertSame(9, $exec->fresh()->stage);
    }

    public function test_admin_reviews_client_document(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-DR', 'subject' => 'مستند', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2]);
        $doc = $exec->documents()->create(['label' => 'السند', 'status' => 'مرفوع', 'path' => 'exec-docs/1/x.pdf']);

        $this->actingAs($this->admin())->post(route('exec-flow.documents.review', [$exec, $doc]), ['decision' => 'accept'])->assertRedirect();
        $this->assertSame('مقبول', $doc->fresh()->status);

        // لا يمكن مراجعة مستند لم يُرفَع
        $pending = $exec->documents()->create(['label' => 'الهوية', 'status' => 'مطلوب']);
        $this->actingAs($this->admin())->post(route('exec-flow.documents.review', [$exec, $pending]), ['decision' => 'accept'])->assertStatus(422);
    }

    public function test_office_message_reaches_client_no_ai(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-M', 'subject' => 'محادثة', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2]);

        $this->actingAs($this->admin())->post(route('exec-flow.messages.store', $exec), ['body' => 'نحتاج توضيحاً.'])->assertNoContent();

        $last = $exec->messages()->latest('id')->first();
        $this->assertSame('admin', $last->who);
        $this->assertSame('نحتاج توضيحاً.', $last->body);
        $this->assertFalse($exec->messages->contains(fn ($m) => $m->who === 'ai'));
    }

    public function test_setfee_rejected_by_non_admin(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-G', 'subject' => 'x', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2]);

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), ['action' => 'setFee', 'fee' => 5000])->assertForbidden();
    }
}
