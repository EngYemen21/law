<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketAssignment;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * توزيع التذاكر التلقائي واليدوي: محرك TicketAssignment يحترم distribution_mode (auto/manual)،
 * والإدارة تستطيع تشغيل التوزيع التلقائي على غير المسندة فقط، وتبديل وضع كل محامٍ.
 */
class DistributionTest extends TestCase
{
    use RefreshDatabase;

    private function openTicket(User $client, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'user_id' => $client->id,
            'number' => 'SB-'.uniqid(),
            'type' => 'نزاع',
            'status' => 'قيد التحليل',
            'tone' => 'b-blue',
        ], $overrides));
    }

    public function test_pick_lawyer_excludes_manual_lawyers(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $auto = User::factory()->create(['role' => Role::Lawyer, 'distribution_mode' => 'auto']);
        $manual = User::factory()->create(['role' => Role::Lawyer, 'distribution_mode' => 'manual']);
        $ticket = $this->openTicket($client);

        $picked = TicketAssignment::pickLawyer($ticket);

        $this->assertNotNull($picked);
        $this->assertSame($auto->id, $picked->id);
        $this->assertNotSame($manual->id, $picked->id);
    }

    /**
     * 🔴 عدّاد «العاجلة» كان يقرأ `$t->priority` من صفوف العرض — وهي مصفوفات لا نماذج —
     * فتسقط شاشة التوزيع كلّها بخطأ تشغيليّ متى وُجدت تذكرةٌ مفتوحة واحدة.
     */
    public function test_distribute_screen_renders_and_counts_urgent_tickets(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $this->openTicket($client, ['priority' => TicketJourney::PRIORITIES[0]]);
        $this->openTicket($client, ['priority' => TicketJourney::PRIORITIES[1]]);

        $this->actingAs($admin)
            ->get(route('admin.distribute'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/distribute')
                ->where('kpis.total', 2)
                ->where('kpis.urgent', 1));
    }

    public function test_distribute_auto_assigns_unassigned_tickets_only(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);

        // تذكرة مسندة سابقاً — يجب أن لا تُلمَس
        $assigned = $this->openTicket($client, [
            'assigned_lawyer' => 'محامٍ سابق',
            'assigned_lawyer_id' => $lawyer->id,
        ]);
        // تذكرة غير مسندة — يجب أن تُوزَّع
        $unassigned = $this->openTicket($client);

        $this->actingAs($admin)
            ->post(route('admin.distribute.auto'))
            ->assertRedirect();

        $assigned->refresh();
        $unassigned->refresh();

        $this->assertSame($lawyer->id, $assigned->assigned_lawyer_id, 'المسندة سابقاً لم تُلمَس');
        $this->assertSame('محامٍ سابق', $assigned->assigned_lawyer, 'اسم المحامي السابق محفوظ');
        $this->assertNotNull($unassigned->assigned_lawyer_id, 'غير المسندة تم توزيعها');
    }

    public function test_distribute_auto_respects_department_match(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        // محامٍ مدني ومحامٍ تجاري؛ كلاهما auto
        $civil = User::factory()->create(['role' => Role::Lawyer, 'department' => 'مدني']);
        $commercial = User::factory()->create(['role' => Role::Lawyer, 'department' => 'تجاري']);

        $ticket = $this->openTicket($client, ['department' => 'مدني']);

        $this->actingAs($admin)
            ->post(route('admin.distribute.auto'))
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame($civil->id, $ticket->assigned_lawyer_id, 'محامٍ مدني مطابق للقسم');
    }

    public function test_distribute_auto_race_protection(): void
    {
        // يثبت أن إعادة الفحص بعد القفل (fresh()) داخل transaction تتجاوز بأمان ما قد أُسند
        // متزامناً قبل تنفيذ الدفعة — دون خطأ أو إعادة إسناد مضاعفة.
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);

        $ticket = $this->openTicket($client);

        // نُسنِد التذكرة قبل تنفيذ الدفعة (محاكاة race)
        $ticket->update(['assigned_lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id]);

        $this->actingAs($admin)
            ->post(route('admin.distribute.auto'))
            ->assertRedirect(); // لا يفشل، يتخطّاها بأمان

        $ticket->refresh();
        $this->assertSame($lawyer->id, $ticket->assigned_lawyer_id, 'الإسناد الأصلي محفوظ');
        // لا توجد رسالة «توزيع آلي» مكررة على التذكرة
        $autoMessages = $ticket->messages()->where('role', 'توزيع آلي')->count();
        $this->assertSame(0, $autoMessages, 'لم تُنشَر رسالة توزيع آلي على تذكرة أُسندت متزامناً');
    }

    public function test_distribute_auto_creates_audit_message(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->openTicket($client);

        $this->actingAs($admin)
            ->post(route('admin.distribute.auto'))
            ->assertRedirect();

        $ticket->refresh();
        $this->assertTrue(
            $ticket->messages->contains(fn ($m) => $m->who === 'note' && $m->role === 'توزيع آلي'),
            'رسالة تدقيق التوزيع الآلي أُنشئت'
        );
    }

    public function test_non_admin_cannot_access_distribute_auto(): void
    {
        // EnsureRole يعيد التوجيه (302) بدل 403 عند غياب الدور (نمط المشروع).
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)
            ->post(route('admin.distribute.auto'))
            ->assertRedirect();
    }

    public function test_lawyer_mode_toggle(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'distribution_mode' => 'auto']);

        $this->actingAs($admin)
            ->post(route('admin.lawyers.mode', $lawyer))
            ->assertRedirect();

        $this->assertSame('manual', $lawyer->fresh()->distribution_mode, 'تبديل من auto إلى manual');

        // تبديل عكسي
        $this->actingAs($admin)
            ->post(route('admin.lawyers.mode', $lawyer))
            ->assertRedirect();

        $this->assertSame('auto', $lawyer->fresh()->distribution_mode, 'تبديل من manual إلى auto');
    }

    public function test_toggle_mode_rejects_non_lawyer_user(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($admin)
            ->post(route('admin.lawyers.mode', $client))
            ->assertStatus(422);
    }

    public function test_distribute_screen_includes_cases_executions_and_consults(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        $this->openTicket($client);
        LegalCase::create([
            'user_id' => $client->id,
            'number'  => 'CAS-'.uniqid(),
            'type'    => 'تجاري',
            'status'  => 'جلسة أولى',
            'tone'    => 'b-amber',
        ]);
        Execution::create([
            'user_id' => $client->id,
            'number'  => 'EX-'.uniqid(),
            'subject' => 'سند لأمر',
            'status'  => 'دراسة الطلب',
            'tone'    => 'b-purple',
        ]);
        Consult::create([
            'user_id'    => $client->id,
            'ref'        => 'CON-'.uniqid(),
            'status'     => 'جديدة',
            'type'       => 'استشارة',
            'subject'    => 'استشارة تجارية',
            'channel'    => 'هاتفية',
            'day'        => 'الأحد',
            'time'       => '10ص',
            'when_label' => 'الأحد · 10ص',
            'lawyer'     => '—',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.distribute'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/distribute')
                ->has('tickets', 1)
                ->has('cases', 1)
                ->has('executions', 1)
                ->has('consults', 1)
                ->where('kpis.casesTotal', 1)
                ->where('kpis.executionsTotal', 1)
                ->where('kpis.consultsTotal', 1)
                ->where('kpis.grandTotal', 4));
    }

    public function test_admin_can_assign_case_to_active_lawyer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $case = LegalCase::create([
            'user_id' => $client->id,
            'number'  => 'CAS-'.uniqid(),
            'type'    => 'تجاري',
            'status'  => 'جلسة أولى',
            'tone'    => 'b-amber',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.distribute.assign-case', $case), ['lawyer_id' => $lawyer->id])
            ->assertRedirect();

        $case->refresh();
        $this->assertSame($lawyer->id, $case->assigned_lawyer_id);
        $this->assertSame($lawyer->name, $case->assigned_lawyer);
    }

    public function test_admin_can_assign_execution_to_active_lawyer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $execution = Execution::create([
            'user_id' => $client->id,
            'number'  => 'EX-'.uniqid(),
            'subject' => 'سند لأمر',
            'status'  => 'دراسة الطلب',
            'tone'    => 'b-purple',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.distribute.assign-execution', $execution), ['lawyer_id' => $lawyer->id])
            ->assertRedirect();

        $execution->refresh();
        $this->assertSame($lawyer->id, $execution->assigned_lawyer_id);
        $this->assertSame($lawyer->name, $execution->assigned_lawyer);
    }

    public function test_admin_can_assign_consult_to_active_lawyer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $consult = Consult::create([
            'user_id'    => $client->id,
            'ref'        => 'CON-'.uniqid(),
            'status'     => 'جديدة',
            'type'       => 'استشارة',
            'subject'    => 'استشارة تجارية',
            'channel'    => 'هاتفية',
            'day'        => 'الأحد',
            'time'       => '10ص',
            'when_label' => 'الأحد · 10ص',
            'lawyer'     => '—',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.distribute.assign-consult', $consult), ['lawyer_id' => $lawyer->id])
            ->assertRedirect();

        $consult->refresh();
        $this->assertSame($lawyer->id, $consult->assigned_lawyer_id);
        $this->assertSame($lawyer->name, $consult->lawyer);
    }

    public function test_cannot_assign_frozen_or_closed_ticket(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $frozenTicket = $this->openTicket($client, ['is_frozen' => true]);
        $this->actingAs($admin)
            ->post(route('admin.distribute.assign', $frozenTicket), ['lawyer_id' => $lawyer->id])
            ->assertStatus(422);

        $closedTicket = $this->openTicket($client, ['status' => 'مغلقة']);
        $this->actingAs($admin)
            ->post(route('admin.distribute.assign', $closedTicket), ['lawyer_id' => $lawyer->id])
            ->assertStatus(422);
    }

    public function test_cannot_assign_closed_or_archived_case(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $closedCase = LegalCase::create([
            'user_id' => $client->id,
            'number'  => 'CAS-'.uniqid(),
            'type'    => 'تجاري',
            'status'  => 'مغلقة',
            'tone'    => 'b-grey',
        ]);
        $this->actingAs($admin)
            ->post(route('admin.distribute.assign-case', $closedCase), ['lawyer_id' => $lawyer->id])
            ->assertStatus(422);

        $archivedCase = LegalCase::create([
            'user_id' => $client->id,
            'number'  => 'CAS-'.uniqid(),
            'type'    => 'تجاري',
            'status'  => 'مؤرشفة',
            'tone'    => 'b-grey',
        ]);
        $this->actingAs($admin)
            ->post(route('admin.distribute.assign-case', $archivedCase), ['lawyer_id' => $lawyer->id])
            ->assertStatus(422);
    }

    public function test_cannot_assign_closed_or_rejected_execution(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $closedExec = Execution::create([
            'user_id' => $client->id,
            'number'  => 'EX-'.uniqid(),
            'subject' => 'سند لأمر',
            'status'  => 'مغلق',
            'tone'    => 'b-grey',
        ]);
        $this->actingAs($admin)
            ->post(route('admin.distribute.assign-execution', $closedExec), ['lawyer_id' => $lawyer->id])
            ->assertStatus(422);

        $rejectedExec = Execution::create([
            'user_id'  => $client->id,
            'number'   => 'EX-'.uniqid(),
            'subject'  => 'سند لأمر',
            'status'   => 'دراسة الطلب',
            'decision' => 'مرفوض',
            'tone'     => 'b-red',
        ]);
        $this->actingAs($admin)
            ->post(route('admin.distribute.assign-execution', $rejectedExec), ['lawyer_id' => $lawyer->id])
            ->assertStatus(422);
    }

    public function test_cannot_assign_pre_session_or_live_consult(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $preSessionConsult = Consult::create([
            'user_id'    => $client->id,
            'ref'        => 'CON-'.uniqid(),
            'status'     => 'بانتظار السداد',
            'type'       => 'استشارة',
            'subject'    => 'استشارة تجارية',
            'channel'    => 'هاتفية',
            'day'        => 'الأحد',
            'time'       => '10ص',
            'when_label' => 'الأحد · 10ص',
            'lawyer'     => '—',
        ]);
        $this->actingAs($admin)
            ->post(route('admin.distribute.assign-consult', $preSessionConsult), ['lawyer_id' => $lawyer->id])
            ->assertStatus(422);

        $liveConsult = Consult::create([
            'user_id'    => $client->id,
            'ref'        => 'CON-'.uniqid(),
            'status'     => 'قيد الاستشارة',
            'session'    => 'جلسة جارية',
            'type'       => 'استشارة',
            'subject'    => 'استشارة تجارية',
            'channel'    => 'مرئية',
            'day'        => 'الأحد',
            'time'       => '10ص',
            'when_label' => 'الأحد · 10ص',
            'lawyer'     => '—',
        ]);
        $this->actingAs($admin)
            ->post(route('admin.distribute.assign-consult', $liveConsult), ['lawyer_id' => $lawyer->id])
            ->assertStatus(422);
    }
}
