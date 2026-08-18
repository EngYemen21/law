<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحة المحامي — مؤشرات حقيقية، مهام محفوظة، مساعد ذكي، وفلترة بالمحامي المسجّل.
 */
class LawyerPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_real_kpis(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);

        // تذكرة محالة لهذا المحامي + ملخص بانتظار الاعتماد
        $ticket = Ticket::create(['user_id' => $client->id, 'number' => 'SB-2026-5001', 'type' => 'نزاع تجاري',
            'assigned_lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id, 'status' => 'بانتظار اعتماد المستشار', 'tone' => 'b-amber']);
        $ticket->summary()->create(['lawyer_id' => $lawyer->id, 'case_summary' => 'ملخص', 'attachments_summary' => '', 'facts' => '', 'key_points' => '', 'status' => 'awaiting_lawyer']);

        Task::create(['assigned_to' => $lawyer->id, 'title' => 'مهمة مفتوحة', 'status' => 'مفتوحة', 'tone' => 'b-amber']);
        Task::create(['assigned_to' => $lawyer->id, 'title' => 'مهمة منجزة', 'status' => 'منجزة', 'tone' => 'b-green']);
        // created_by عمود نصّي يخزّن الاسم (كما يكتبه Staff\MeetingController فعلاً) — كان الاختبار
        // يضع معرّفاً رقمياً فيطابق العدّاد المعطوب القديم الذي قارن الاسم بالمعرّف
        Meeting::create(['user_id' => $client->id, 'ref' => 'MTG-1', 'title' => 'اجتماع', 'type' => 'اجتماع عميل',
            'when_label' => 'الأحد', 'status' => 'قادم', 'approve' => 'معتمد', 'created_by' => $lawyer->name]);

        $this->actingAs($lawyer)->get(route('lawyer.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('lawyer/dashboard')
                ->where('pendingSummaries', 1)
                ->where('openTasks', 1)
                ->where('openMeetings', 1)
                ->has('tickets', 1));
    }

    public function test_lawyer_creates_and_completes_task(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $this->actingAs($lawyer)->post(route('lawyer.tasks.store'), ['title' => 'صياغة خطاب مطالبة', 'ref' => 'SB-1', 'due' => 'غداً'])->assertRedirect();
        $task = Task::firstOrFail();
        $this->assertSame($lawyer->id, $task->assigned_to);
        $this->assertSame('مفتوحة', $task->status);

        $this->actingAs($lawyer)->post(route('lawyer.tasks.complete', $task))->assertRedirect();
        $this->assertSame('منجزة', $task->fresh()->status);
    }

    public function test_lawyer_sees_only_own_tasks(): void
    {
        $a = User::factory()->create(['role' => Role::Lawyer]);
        $b = User::factory()->create(['role' => Role::Lawyer]);
        Task::create(['assigned_to' => $a->id, 'title' => 'مهمة أ', 'status' => 'مفتوحة', 'tone' => 'b-amber']);
        Task::create(['assigned_to' => $b->id, 'title' => 'مهمة ب', 'status' => 'مفتوحة', 'tone' => 'b-amber']);

        $this->actingAs($a)->get(route('lawyer.tasks'))
            ->assertInertia(fn ($p) => $p->has('tasks', 1)->where('tasks.0.title', 'مهمة أ'));
    }

    public function test_lawyer_cannot_complete_another_lawyers_task(): void
    {
        $a = User::factory()->create(['role' => Role::Lawyer]);
        $b = User::factory()->create(['role' => Role::Lawyer]);
        $task = Task::create(['assigned_to' => $b->id, 'title' => 'مهمة ب', 'status' => 'مفتوحة', 'tone' => 'b-amber']);

        $this->actingAs($a)->post(route('lawyer.tasks.complete', $task))->assertStatus(403);
    }

    public function test_assistant_generate_returns_draft(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        // بلا مفاتيح AI (phpunit يصفّرها) → يعود الاحتياط القالبي
        $this->actingAs($lawyer)->postJson(route('lawyer.assistant.generate'), [
            'kind' => 'lawahe', 'docType' => 'لائحة دعوى', 'ref' => 'SB-1', 'context' => 'وقائع النزاع.',
        ])->assertOk()->assertJson(fn ($j) => $j->has('draft'));
    }

    public function test_case_and_exec_lists_filter_by_lawyer(): void
    {
        $mine = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create(['user_id' => $client->id, 'number' => 'SB-2026-6001', 'type' => 'تجاري', 'status' => 'مكتملة', 'tone' => 'b-green']);

        LegalCase::create(['user_id' => $client->id, 'ticket_id' => $ticket->id, 'number' => 'CASE-A', 'type' => 'تجاري',
            'assigned_lawyer' => $mine->name, 'assigned_lawyer_id' => $mine->id, 'status' => 'منظورة', 'tone' => 'b-blue']);
        LegalCase::create(['user_id' => $client->id, 'ticket_id' => $ticket->id, 'number' => 'CASE-B', 'type' => 'تجاري',
            'assigned_lawyer' => $other->name, 'assigned_lawyer_id' => $other->id, 'status' => 'منظورة', 'tone' => 'b-blue']);
        Execution::create(['user_id' => $client->id, 'number' => 'EXE-A', 'subject' => 'تنفيذ',
            'assigned_lawyer' => $mine->name, 'assigned_lawyer_id' => $mine->id, 'status' => 'جديد', 'tone' => 'b-blue']);
        Execution::create(['user_id' => $client->id, 'number' => 'EXE-B', 'subject' => 'تنفيذ',
            'assigned_lawyer' => $other->name, 'assigned_lawyer_id' => $other->id, 'status' => 'جديد', 'tone' => 'b-blue']);

        $this->actingAs($mine)->get(route('lawyer.cases'))
            ->assertInertia(fn ($p) => $p->has('cases', 1)->where('cases.0.no', 'CASE-A'));
        // التبويب الموحّد (execflow): بطاقة التدفّق تستخدم المفتاح id، ومحصورة بالمسند إليه
        $this->actingAs($mine)->get(route('lawyer.execs'))
            ->assertInertia(fn ($p) => $p->component('execflow')->has('execs', 1)->where('execs.0.id', 'EXE-A'));
    }

    public function test_calendar_shows_only_lawyers_events(): void
    {
        $mine = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create(['user_id' => $client->id, 'number' => 'SB-2026-7001', 'type' => 'تجاري', 'status' => 'مكتملة', 'tone' => 'b-green']);

        $mineCase = LegalCase::create(['user_id' => $client->id, 'ticket_id' => $ticket->id, 'number' => 'CASE-M', 'type' => 'تجاري',
            'assigned_lawyer_id' => $mine->id, 'status' => 'منظورة', 'tone' => 'b-blue']);
        $otherCase = LegalCase::create(['user_id' => $client->id, 'ticket_id' => $ticket->id, 'number' => 'CASE-O', 'type' => 'تجاري',
            'assigned_lawyer_id' => $other->id, 'status' => 'منظورة', 'tone' => 'b-blue']);
        $mineCase->hearings()->create(['title' => 'جلسة أولى', 'day' => 'الأحد', 'time' => '10:00 ص', 'status' => 'مجدولة']);
        $otherCase->hearings()->create(['title' => 'جلسة ثانية', 'day' => 'الاثنين', 'time' => '11:00 ص', 'status' => 'مجدولة']);

        $this->actingAs($mine)->get(route('lawyer.calendar'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('lawyer/calendar')->has('events', 1));
    }
}
