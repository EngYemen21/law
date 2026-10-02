<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\AiClientVoice;
use App\Support\ExecFlow;
use App\Support\ExecutionCreation;
use App\Support\LegalCatalogue;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **المنفَّذ ضده في ملفّ التنفيذ** (قرار المالك 2026-10-02).
 *
 * ثبت في المتصفّح: الاسم يُنسخ من «الخصم» في التذكرة وحدها، والتذكرة لم تكن تطلبه إلّا لقسم التنفيذ،
 * فيُفتح ملفّ تنفيذ الحكم بـ«المنفَّذ ضده —» ولا مسار يعدّله. الآن:
 *  (أ) يحدّده المحامي المسنَد أو الإدارة على الملفّ، واستبدالُ اسمٍ قائم بسبب؛
 *  (ب) يُرفع مع طلب فتح التنفيذ من القضيّة (ومع فتح الإدارة المباشر) وينتقل للملفّ؛
 *  (ج) نموذج التذكرة يطلب الخصم لكلّ الأقسام.
 */
class ExecutionDefendantTest extends TestCase
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

    private function ruledCase(?string $opponent = null): LegalCase
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-DF-'.uniqid(), 'type' => 'نزاع تجاري',
            'status' => 'محولة إلى قضية', 'tone' => 'b-green', 'opponent_name' => $opponent,
        ]);

        return LegalCase::create([
            'user_id' => $this->client->id, 'ticket_id' => $ticket->id, 'number' => 'CASE-DF-'.uniqid(), 'title' => 'دعوى',
            'type' => 'نزاع تجاري', 'status' => 'صدر الحكم', 'tone' => 'b-cyan', 'update_text' => '—',
            'assigned_lawyer_id' => $this->lawyer->id, 'assigned_lawyer' => $this->lawyer->name,
        ]);
    }

    private function openExec(string $defendant = '', int $stage = 8): Execution
    {
        return Execution::create([
            'user_id' => $this->client->id, 'number' => 'EXE-DF-'.uniqid(), 'subject' => 'تنفيذ حكم', 'sanad' => 'حكم قضائي',
            'defendant' => $defendant, 'amount' => 150000, 'stage' => $stage, 'status' => ExecFlow::label($stage), 'tone' => ExecFlow::tone($stage),
            'assigned_lawyer_id' => $this->lawyer->id, 'assigned_lawyer' => $this->lawyer->name,
        ]);
    }

    private function setDefendant(User $by, Execution $exec, string $name, string $reason = '')
    {
        return $this->actingAs($by)->post(route('exec-flow.act', $exec), ['action' => 'setDefendant', 'defendant' => $name, 'reason' => $reason]);
    }

    // ── (أ) على ملفّ التنفيذ ──

    public function test_the_assigned_lawyer_fills_a_missing_defendant_without_a_reason(): void
    {
        $exec = $this->openExec();

        $this->setDefendant($this->lawyer, $exec, '  شركة المدين التجاريّة ')->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('شركة المدين التجاريّة', $exec->fresh()->defendant);
        $row = JourneyTransition::where('entity_id', $exec->id)->where('transition', 'exec.set_defendant')->sole();
        $this->assertSame($this->lawyer->id, $row->actor_id);
        $this->assertSame('شركة المدين التجاريّة', $row->payload['to']);

        // ملاحظةٌ داخليّة للمكتب لا رسالةٌ للعميل — فلا يُعدّ تدخّلاً بشريّاً يوقف ردود المساعد
        $note = $exec->messages()->latest('id')->first();
        $this->assertSame('note', $note->who);
        $this->assertFalse(AiClientVoice::humanIntervened($exec->fresh()));
        $this->assertFalse(UserNotification::where('user_id', $this->client->id)->where('body', 'like', '%المنفَّذ ضده%')->exists());
    }

    public function test_replacing_a_name_needs_a_reason_and_a_different_name(): void
    {
        $exec = $this->openExec('مؤسسة قديمة');

        $this->setDefendant($this->admin, $exec, 'شركة المدين')->assertStatus(422);
        $this->setDefendant($this->admin, $exec, 'مؤسسة قديمة', 'الاسم كما ورد في الحكم')->assertStatus(422);
        $this->setDefendant($this->admin, $exec, 'ش', 'الاسم كما ورد في الحكم')->assertStatus(422);
        $this->assertSame('مؤسسة قديمة', $exec->fresh()->defendant);

        $this->setDefendant($this->admin, $exec, 'شركة المدين', 'الاسم كما ورد في منطوق الحكم')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('شركة المدين', $exec->fresh()->defendant);
        $row = JourneyTransition::where('entity_id', $exec->id)->where('transition', 'exec.set_defendant')->sole();
        $this->assertSame(['from' => 'مؤسسة قديمة', 'to' => 'شركة المدين'], array_intersect_key((array) $row->payload, ['from' => 0, 'to' => 0]));
    }

    public function test_only_the_assigned_lawyer_or_admin_on_an_open_file(): void
    {
        $exec = $this->openExec();
        $colleague = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $colleague->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->setDefendant($colleague, $exec, 'شركة المدين')->assertForbidden();
        $this->setDefendant($employee, $exec, 'شركة المدين')->assertForbidden();
        $this->setDefendant($this->client, $exec, 'شركة المدين')->assertForbidden();

        $closed = $this->openExec(stage: 9);
        $closed->forceFill(['closed_reason' => 'تمّ التحصيل كاملاً'])->save();
        $this->setDefendant($this->admin, $closed, 'شركة المدين')->assertStatus(422);
        $this->assertSame('', (string) $exec->fresh()->defendant);
    }

    public function test_the_card_flag_follows_the_same_rule(): void
    {
        $exec = $this->openExec();
        $flag = fn (User $u) => (function () use ($u, $exec) {
            $this->actingAs($u);

            return $exec->fresh()->toFlowCard(internal: $u->role !== Role::Client)['canEditParties'];
        })();

        $this->assertTrue($flag($this->lawyer));
        $this->assertTrue($flag($this->admin));
        $this->assertFalse($flag($this->client));
        $this->assertFalse($flag(User::factory()->create(['role' => Role::Lawyer, 'status' => 'active'])));
    }

    /** «مغلق» مرّةً واحدة في ترويسة الملفّ — شارة المرحلة تقولها في المرحلة 9، والشارة الإضافيّة لما أُغلق قبلها. */
    public function test_the_closed_badge_is_not_repeated(): void
    {
        $this->actingAs($this->admin);
        $atStage = $this->openExec(stage: 9);
        $this->assertTrue($atStage->toFlowCard(internal: true)['closed']);
        $this->assertFalse($atStage->toFlowCard(internal: true)['closedBadge']);
        $this->assertTrue($this->openExec(stage: 8)->forceFill(['status' => Execution::CLOSED_STATUSES[0]])->toFlowCard(internal: true)['closedBadge']);
        $this->assertFalse($this->openExec(stage: 8)->toFlowCard(internal: true)['closedBadge']);
    }

    // ── (ب) مع طلب فتح التنفيذ من القضيّة ──

    public function test_the_request_carries_the_defendant_into_the_file(): void
    {
        $case = $this->ruledCase();
        $request = ['reason' => 'امتنع المحكوم عليه عن السداد', 'amount' => 150000];

        $this->actingAs($this->lawyer)->post(route('lawyer.cases.execution-request', $case), $request)->assertSessionHasErrors('defendant');
        $this->assertNull($case->fresh()->execution_requested_at);

        $this->actingAs($this->lawyer)->post(route('lawyer.cases.execution-request', $case), $request + ['defendant' => 'شركة المدين'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('شركة المدين', $case->fresh()->execution_request_defendant);

        $this->actingAs($this->admin)->get(route('admin.approvals'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('executionRequests.0.defendant', 'شركة المدين'));

        $this->actingAs($this->admin)->post(route('admin.cases.execution-request.approve', $case))->assertRedirect();
        $this->assertSame('شركة المدين', $case->execution()->sole()->defendant);
        $this->assertNull($case->fresh()->execution_request_defendant);
    }

    public function test_the_admin_opening_directly_needs_the_defendant(): void
    {
        $case = $this->ruledCase();

        $this->actingAs($this->admin)->post(route('admin.cases.execute', $case), ['amount' => 120000])->assertSessionHasErrors('defendant');
        $this->assertFalse($case->execution()->exists());

        $this->actingAs($this->admin)->post(route('admin.cases.execute', $case), ['amount' => 120000, 'defendant' => 'مؤسسة المحكوم عليه'])->assertRedirect();
        $this->assertSame('مؤسسة المحكوم عليه', $case->execution()->sole()->defendant);
    }

    /** الخصم في التذكرة اقتراحٌ للخانة، واحتياطٌ لطلبٍ رُفع قبل الحقل. */
    public function test_the_ticket_opponent_is_the_hint_and_the_fallback(): void
    {
        $case = $this->ruledCase(opponent: 'شركة الخصم');

        $this->actingAs($this->lawyer)->get(route('lawyer.cases.show', $case))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('executionDefendantHint', 'شركة الخصم'));

        $this->assertSame('شركة الخصم', ExecutionCreation::fromCase($case, $this->admin, amount: 1000)->defendant);
    }

    // ── (ج) نموذج التذكرة ──

    public function test_a_non_enforcement_ticket_keeps_its_opponent(): void
    {
        $labor = LegalCatalogue::department('labor');

        $this->actingAs($this->client)->post('/tickets', [
            'department_id' => $labor->id, 'service_id' => $labor->services->first()->id, 'subject' => 'نزاع', 'details' => 'وقائع النزاع',
            'opponent_name' => 'شركة الخصم التجاريّة',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $ticket = Ticket::where('user_id', $this->client->id)->latest('id')->firstOrFail();
        $this->assertSame('شركة الخصم التجاريّة', $ticket->opponent_name);
        $this->assertNull($ticket->exec_sanad);
    }
}
