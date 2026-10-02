<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ExecFlow;
use App\Support\ExecutionCreation;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **مبلغ المطالبة في ملفّ التنفيذ** (قرار المالك 2026-09-30).
 *
 * ثبت في المتصفّح: ملفّ التنفيذ المفتوح من قضيّة يأخذ مبلغه من التذكرة وحدها، فيُفتح بصفرٍ حين لم يُدخل
 * العميل مبلغاً (والحكم «150,000» نصٌّ حرّ) — ولا مسار يعدّله، فيستحيل تسجيل أيّ تحصيل.
 * الآن: المبلغ المحكوم به يُرفع مع طلب التنفيذ، ويُصحَّح على الملفّ قبل أوّل تحصيل.
 */
class ExecutionClaimAmountTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $this->lawyer->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $this->admin = User::factory()->create(['role' => Role::Admin]);
    }

    private function ruledCase(?int $claimAmount = null): LegalCase
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-CA-'.uniqid(), 'type' => 'نزاع تجاري',
            'status' => 'محولة إلى قضية', 'tone' => 'b-green', 'claim_amount' => $claimAmount,
        ]);

        return LegalCase::create([
            'user_id' => $this->client->id, 'ticket_id' => $ticket->id, 'number' => 'CASE-CA-'.uniqid(), 'title' => 'دعوى',
            'type' => 'نزاع تجاري', 'status' => 'صدر الحكم', 'tone' => 'b-cyan', 'update_text' => '—',
            'ruling' => 'حكمت المحكمة بإلزام المدّعى عليه بدفع 150,000 ريال.',
            'assigned_lawyer_id' => $this->lawyer->id, 'assigned_lawyer' => $this->lawyer->name,
        ]);
    }

    private function openExec(int $amount, int $collected = 0, ?User $lawyer = null): Execution
    {
        $lawyer ??= $this->lawyer;

        return Execution::create([
            'user_id' => $this->client->id, 'number' => 'EXE-CA-'.uniqid(), 'subject' => 'تنفيذ حكم', 'sanad' => 'حكم قضائي',
            'amount' => $amount, 'collected' => $collected, 'stage' => 8, 'status' => ExecFlow::label(8), 'tone' => ExecFlow::tone(8),
            'paid' => true, 'exec_no' => 'EXE-TN-'.uniqid(), 'registered_at' => now()->subDay()->toDateString(),
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);
    }

    private function setAmount(User $by, Execution $exec, int $amount, string $reason = 'المبلغ المحكوم به في منطوق الحكم')
    {
        return $this->actingAs($by)->post(route('exec-flow.act', $exec), ['action' => 'setClaimAmount', 'amount' => $amount, 'reason' => $reason]);
    }

    public function test_the_request_carries_the_ruled_amount_into_the_file(): void
    {
        $case = $this->ruledCase(claimAmount: null);

        $this->actingAs($this->lawyer)->post(route('lawyer.cases.execution-request', $case), ['reason' => 'امتنع المحكوم عليه عن السداد', 'defendant' => 'شركة المدين التجاريّة'])
            ->assertSessionHasErrors('amount');
        $this->assertNull($case->fresh()->execution_requested_at, 'لا طلب بلا مبلغ');

        $this->actingAs($this->lawyer)->post(route('lawyer.cases.execution-request', $case), ['reason' => 'امتنع المحكوم عليه عن السداد', 'amount' => 150000, 'defendant' => 'شركة المدين التجاريّة'])
            ->assertRedirect();
        $this->assertSame(150000, $case->fresh()->execution_request_amount);

        $this->actingAs($this->admin)->post(route('admin.cases.execution-request.approve', $case))->assertRedirect();

        $exec = Execution::where('case_id', $case->id)->sole();
        $this->assertSame(150000, (int) $exec->amount);
        $this->assertNull($case->fresh()->execution_request_amount, 'الطلب يُفرَغ بالقرار');
        $row = JourneyTransition::where('entity_id', $case->id)->where('transition', 'case.approve_execution_request')->sole();
        $this->assertStringContainsString('150000', json_encode($row->payload, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_the_ticket_amount_is_the_suggestion(): void
    {
        $case = $this->ruledCase(claimAmount: 90000);

        $this->actingAs($this->lawyer)->get(route('lawyer.cases.show', $case))
            ->assertInertia(fn ($page) => $page->where('executionAmountHint', 90000));
    }

    /**
     * **الفتح المباشر من الإدارة يطلب المبلغ المحكوم به** (قرار المالك 2026-09-30: «خانة المبلغ دائماً»).
     * كان يُفتح بمبلغ التذكرة، وبلا مبلغٍ فيها يُفتح بصفرٍ فيتوقّف التحصيل — ثبت في المتصفّح (EXE-2026-7694).
     */
    public function test_the_admin_opening_directly_needs_the_ruled_amount(): void
    {
        $case = $this->ruledCase(claimAmount: 90000);

        $this->actingAs($this->admin)->get(route('admin.cases.show', $case))
            ->assertInertia(fn ($page) => $page->where('case.executionAmountHint', 90000));

        $this->actingAs($this->admin)->post(route('admin.cases.execute', $case))->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->post(route('admin.cases.execute', $case), ['amount' => Execution::MAX_CLAIM_AMOUNT + 1, 'defendant' => 'شركة المدين التجاريّة'])->assertSessionHasErrors('amount');
        $this->assertSame(0, Execution::where('case_id', $case->id)->count(), 'لا ملفّ بلا مبلغٍ صالح');

        $this->actingAs($this->admin)->post(route('admin.cases.execute', $case), ['amount' => 120000, 'defendant' => 'شركة المدين التجاريّة'])->assertRedirect();
        $this->assertSame(120000, (int) Execution::where('case_id', $case->id)->sole()->amount, 'ما أكّدته الإدارة لا مبلغ التذكرة');
    }

    /** الإداريّ الذي فتح الملفّ لا يُشعَر بفعله، وزميله يُشعَر — في الفتح من القضيّة ومن التذكرة. */
    public function test_the_opening_admin_is_not_told_of_his_own_act(): void
    {
        $colleague = User::factory()->create(['role' => Role::Admin]);
        $case = $this->ruledCase();

        $this->actingAs($this->admin)->post(route('admin.cases.execute', $case), ['amount' => 120000, 'defendant' => 'شركة المدين التجاريّة'])->assertRedirect();
        $fromCase = Execution::where('case_id', $case->id)->sole();

        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-CA-'.uniqid(), 'type' => 'تنفيذ', 'status' => 'بانتظار قرار المآل',
            'tone' => 'b-grey', 'assigned_lawyer_id' => $this->lawyer->id, 'assigned_lawyer' => $this->lawyer->name,
        ]);
        $fromTicket = ExecutionCreation::fromTicket($ticket, $this->admin, 'سندٌ لأمر مستحقّ');

        foreach ([$fromCase, $fromTicket] as $exec) {
            $notices = fn (User $u) => UserNotification::where('user_id', $u->id)->where('body', 'like', "%{$exec->number}%")->where('body', 'like', 'فُتح%')->count();
            $this->assertSame(0, $notices($this->admin), $exec->number);
            $this->assertSame(1, $notices($colleague), $exec->number);
        }
    }

    /** مبلغ التذكرة بالحدّ الواحد نفسه، واسمه عربيّ في رسالة الرفض. */
    public function test_the_ticket_amount_has_the_same_ceiling(): void
    {
        $message = (string) $this->actingAs($this->client)->postJson('/tickets', [
            'type' => 'تنفيذ سند', 'subject' => 'حدّ المبلغ', 'details' => 'نصّ', 'claim_amount' => Execution::MAX_CLAIM_AMOUNT + 1,
        ])->assertStatus(422)->json('errors.claim_amount.0');

        $this->assertStringContainsString('مبلغ المطالبة', $message);
        $this->assertStringNotContainsString('claim', $message);
    }

    /** تذكرةٌ قديمة بمبلغٍ لا يتّسع له الملفّ تُحوَّل بلا 500 — «لم يُحدَّد» ثمّ يُصحَّح بالمسار القائم. */
    public function test_an_oversized_legacy_ticket_amount_opens_the_file_without_an_amount(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-CA-'.uniqid(), 'type' => 'تنفيذ', 'status' => 'بانتظار قرار المآل',
            'tone' => 'b-grey', 'claim_amount' => Execution::MAX_CLAIM_AMOUNT + 1,
            'assigned_lawyer_id' => $this->lawyer->id, 'assigned_lawyer' => $this->lawyer->name,
        ]);
        $this->assertSame(0, (int) ExecutionCreation::fromTicket($ticket, $this->admin)->amount);

        $case = $this->ruledCase(claimAmount: Execution::MAX_CLAIM_AMOUNT + 1);
        $this->assertSame(0, (int) ExecutionCreation::fromCase($case, $this->admin)->amount);

        $this->assertSame(90000, Execution::fitClaimAmount(90000));
        $this->assertSame(0, Execution::fitClaimAmount(null));
        $this->assertSame(0, Execution::fitClaimAmount(0));
    }

    public function test_the_assigned_lawyer_sets_a_missing_amount_and_collection_works(): void
    {
        $exec = $this->openExec(amount: 0);

        $this->actingAs($this->lawyer)->post(route('exec-flow.act', $exec), ['action' => 'addCollection', 'amount' => 20000])->assertStatus(422);

        $this->setAmount($this->lawyer, $exec, 150000)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(150000, (int) $exec->fresh()->amount);

        $row = JourneyTransition::where('entity_id', $exec->id)->where('transition', 'exec.set_claim_amount')->sole();
        $this->assertSame($this->lawyer->id, $row->actor_id);
        $this->assertSame(['from' => 0, 'to' => 150000], array_intersect_key((array) $row->payload, ['from' => 0, 'to' => 0]));
        $this->assertTrue(UserNotification::where('user_id', $this->client->id)->where('body', 'like', '%150,000%')->exists());

        $this->actingAs($this->lawyer)->post(route('exec-flow.act', $exec), ['action' => 'addCollection', 'amount' => 20000])->assertRedirect();
        $this->assertSame(20000, (int) $exec->fresh()->collected);
    }

    public function test_the_admin_can_correct_it(): void
    {
        $exec = $this->openExec(amount: 50000);

        $this->setAmount($this->admin, $exec, 75000)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(75000, (int) $exec->fresh()->amount);
    }

    public function test_only_the_assigned_lawyer_or_admin(): void
    {
        $exec = $this->openExec(amount: 0);
        $other = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $other->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::all());

        $this->setAmount($other, $exec, 150000)->assertForbidden();
        $this->setAmount($employee, $exec, 150000)->assertForbidden();
        $this->setAmount($this->client, $exec, 150000)->assertForbidden();

        $this->assertSame(0, (int) $exec->fresh()->amount);
    }

    public function test_it_is_locked_after_the_first_collection_and_on_a_closed_file(): void
    {
        $collected = $this->openExec(amount: 100000, collected: 10000);
        $this->setAmount($this->lawyer, $collected, 150000)->assertStatus(422);
        $this->assertSame(100000, (int) $collected->fresh()->amount);

        $closed = $this->openExec(amount: 0);
        $closed->update(['stage' => 9, 'status' => ExecFlow::label(9), 'closed_reason' => 'تسوية']);
        $this->setAmount($this->admin, $closed, 150000)->assertStatus(422);
        $this->assertSame(0, (int) $closed->fresh()->amount);
    }

    public function test_it_needs_a_valid_amount_and_a_reason(): void
    {
        $exec = $this->openExec(amount: 0);

        $this->setAmount($this->lawyer, $exec, 0)->assertSessionHasErrors('amount');
        $this->setAmount($this->lawyer, $exec, 150000, 'قصير')->assertStatus(422);

        $this->assertSame(0, (int) $exec->fresh()->amount);
    }

    /**
     * **الحدّ الواحد للمبلغ = سعة عمود `executions.amount`** (int unsigned). كان التحقّق يقبل حتى 999,999,999,999
     * فيُسقط الحفظُ على MySQL بـ500 (ثبت في المتصفّح 2026-09-30)، وطلبٌ بمبلغٍ أكبر يُحفظ ثمّ لا يُعتمد أبداً.
     */
    public function test_an_amount_beyond_the_column_is_refused_on_both_paths(): void
    {
        $this->assertSame(4294967295, Execution::MAX_CLAIM_AMOUNT, 'سعة int unsigned');

        $exec = $this->openExec(amount: 0);
        $this->setAmount($this->lawyer, $exec, Execution::MAX_CLAIM_AMOUNT + 1)->assertStatus(422);
        $this->assertSame(0, (int) $exec->fresh()->amount);
        $this->setAmount($this->lawyer, $exec, Execution::MAX_CLAIM_AMOUNT)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Execution::MAX_CLAIM_AMOUNT, (int) $exec->fresh()->amount);

        $case = $this->ruledCase();
        $this->actingAs($this->lawyer)->post(route('lawyer.cases.execution-request', $case), ['reason' => 'امتنع المحكوم عليه عن السداد', 'amount' => Execution::MAX_CLAIM_AMOUNT + 1, 'defendant' => 'شركة المدين التجاريّة'])
            ->assertStatus(422);
        $this->assertNull($case->fresh()->execution_requested_at, 'لا طلبَ يتعذّر اعتماده');
    }

    /** الأرقام العربيّة والفواصل تُطبَّع في حقلَي المبلغ بدالّةٍ واحدة — كان حقل طلب التنفيذ يرفض «١٥٠٠٠٠». */
    public function test_both_amount_fields_share_one_digit_normalizer(): void
    {
        $lib = (string) file_get_contents(resource_path('js/lib/digits.ts'));
        $this->assertStringContainsString('export function normalizeDigits', $lib);

        foreach (['js/lib/exec-najiz.tsx', 'js/components/babylon/CaseExecutionRequestCard.tsx'] as $file) {
            $src = (string) file_get_contents(resource_path($file));
            $this->assertStringContainsString("import { normalizeDigits } from '@/lib/digits';", $src, $file);
            $this->assertStringNotContainsString('const normalizeDigits', $src, $file);
        }
    }

    public function test_the_najiz_card_says_whether_the_amount_is_editable(): void
    {
        $this->assertTrue($this->openExec(amount: 0)->najizCard()['amountEditable']);
        $this->assertFalse($this->openExec(amount: 100000, collected: 5000)->najizCard()['amountEditable']);
    }
}
