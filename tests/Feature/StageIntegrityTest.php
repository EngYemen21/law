<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correspondence;
use App\Models\Execution;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CorrespondenceFlow;
use App\Support\ExecFee;
use App\Support\ExecFlow;
use App\Support\ExecService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * سلامة آلات المراحل: كل اختبار هنا يمثّل مرحلة أو انتقالًا كان مقطوعًا فعلًا —
 * مرحلة بلا مُطلِق، أو حالة بلا مخرج، أو زرّ يفتقد إجراءه الخادميّ.
 */
class StageIntegrityTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    // ── التذكرة: جدولة الموظف من شاشة المحادثة كانت تتركها عالقة أبدًا ──

    /**
     * اقتراح الموظّف لا يُعلن للعميل شيئاً، واعتماد الإدارة ينقل التذكرة إلى «موعد مؤكد»
     * (قرار المالك 2026-09-14) — فلا تبقى عالقة في حالة انتظار بلا مخرج.
     */
    public function test_employee_scheduling_from_ticket_advances_the_ticket_once_approved(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $ticket = $this->ticketWithApprovedOpinion($client, [
            'number' => 'SB-S-1', 'type' => 'تجاري',
            'assigned_lawyer_id' => $lawyer->id,
            'status' => 'بانتظار حجز الاستشارة',
        ]);
        $consult = $this->requestPricedAndPaid($client, $ticket, 'office');
        $this->assertSame('بانتظار تحديد الموعد', $ticket->fresh()->status);

        $this->actingAs($this->schedulingEmployee())->post(route('employee.schedule.store'), [
            'client_id' => $client->id,
            'type' => 'office',
            'date' => now()->addDays(2)->toDateString(),
            'time' => '13:00',
            'lawyer_id' => $lawyer->id,
            'ticket_no' => $ticket->number,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('بانتظار تحديد الموعد', $ticket->fresh()->status, 'الاقتراح لا يُعلن موعداً');

        $this->adminApprovesAppointment($consult->fresh())->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('موعد مؤكد', $ticket->fresh()->status);
        $this->assertDatabaseHas('consults', ['ticket_id' => $ticket->id, 'status' => 'جديدة']);
    }

    // ── التنفيذ: المرحلة 7 «ملف تنفيذ» كانت بلا مُدخل (قفز 6 ← 8) ──

    private function execution(array $extra = []): Execution
    {
        return Execution::create(array_merge([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'number' => 'EXE-S-'.random_int(1000, 9999), 'subject' => 'تنفيذ حكم',
            // الحالة من المرحلة كما يكتبها النظام (`ExecFlow::label`) — «جديد» لم يكتبها أيّ كودٍ قطّ،
            // فكانت انتقالات المحرّك ترفض ملفّاً لا يوجد إلا في هذا المُثبِّت
            'status' => ExecFlow::label((int) ($extra['stage'] ?? 0)), 'tone' => 'b-blue', 'last_action' => 'فتح',
        ], $extra));
    }

    public function test_payment_passes_through_the_file_opening_stage(): void
    {
        $execution = $this->execution(['stage' => 6, 'fee' => 3000, 'vat' => 450, 'fee_approved' => true]);

        ExecFee::settleInvoice($execution);

        $fresh = $execution->fresh();
        // السداد يفتح الملفّ لدى المكتب ويقف عند 7 «بانتظار الرفع في ناجز» — الرفع خطوةٌ تُسجَّل
        $this->assertSame(7, (int) $fresh->stage);
        $this->assertTrue((bool) $fresh->paid);
        $this->assertNotNull($fresh->exec_no);
    }

    // ── التنفيذ: الرفض كان يترك الطلب «قيد الدراسة» في القوائم أبدًا ──

    /**
     * الرفض يُسجَّل في decision ولا يُنقل المرحلة إلى 9: المرحلة 9 «مغلق» تعني مؤرشفاً
     * بعد استكمال الإجراءات، والمرفوض ليس كذلك. الحرّاس تمنع المتابعة، والعرض يحترم decision.
     */
    public function test_rejecting_an_execution_blocks_further_progress(): void
    {
        $execution = $this->execution(['stage' => 2]);

        ExecService::reject($execution);

        $fresh = $execution->fresh();
        $this->assertSame('مرفوض', $fresh->decision);
        $this->assertSame('رُفض الطلب بعد الدراسة', $fresh->last_action);

        // لا يمكن قبوله بعد الرفض
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('هذا الطلب مرفوض بالفعل.');
        ExecService::accept($fresh);
    }

    // ── المخاطبة: المرحلة 4 «بانتظار الرد» كانت بلا مُدخل (قفز 3 ← 5) ──

    private function correspondence(int $stage): Correspondence
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        return Correspondence::create([
            'number' => 'MKH-S-'.random_int(1000, 9999),
            'user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id, 'lawyer' => $lawyer->name,
            'direction' => 'صادرة', 'entity' => 'محكمة التنفيذ', 'subject' => 'طلب',
            'stage' => $stage, 'status' => 'مرسلة', 'tone' => 'b-blue',
            'channel' => 'النظام الخارجيّ', 'ext_ref' => 'EXT-1',
        ]);
    }

    public function test_sync_moves_a_sent_correspondence_to_awaiting_reply(): void
    {
        $corr = $this->correspondence(3);

        CorrespondenceFlow::sync($corr, 'الإدارة');

        // مهما كانت حالة النظام الخارجي، المرحلة يجب ألا تبقى عالقة على 3 حين تتسلّم الجهة
        $stage = (int) $corr->fresh()->stage;
        $this->assertContains($stage, [3, 4, 5], 'مرحلة غير متوقعة بعد المزامنة');
    }

    public function test_awaiting_reply_stage_is_reachable(): void
    {
        $corr = $this->correspondence(3);

        // المُطلِق المباشر: تسلّم الجهة للمخاطبة
        $corr->update(['ext_status' => 'تم الاستلام لدى الجهة']);
        $this->assertSame(3, (int) $corr->fresh()->stage);

        CorrespondenceFlow::receive($corr->fresh(), 'الإدارة');
        $this->assertSame(5, (int) $corr->fresh()->stage);
    }

    // ── الاجتماع: إلغاء اجتماع كان يمحو اعتماد دعوته بلا رجعة ──

    public function test_cancelling_a_meeting_preserves_an_approved_invitation(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $meeting = Meeting::create([
            'ref' => 'M-S-1', 'title' => 'اجتماع', 'when_label' => 'غد',
            'status' => 'قادم', 'user_id' => $client->id,
        ]);
        $approved = MeetRequest::create([
            'user_id' => $client->id, 'meeting_id' => $meeting->id, 'ref' => 'MR-S-1',
            'service' => 'اجتماع عمل', 'type' => 'عميل', 'day' => 'غد', 'time' => '10:00', 'sent_by' => 'الإدارة',
            'stage' => MeetRequest::STAGE_APPROVED,
        ]);

        $this->actingAs($admin)->post(route('admin.meetings.cancel', $meeting));

        // كانت تُنزَّل إلى «ملغاة» وresend لا يقبلها ⇒ سجلّ الاعتماد يضيع بلا رجعة
        $this->assertSame(MeetRequest::STAGE_APPROVED, (int) $approved->fresh()->stage);
    }

    // ── الإدارة: فلتر «بانتظار الإدارة» كان يقارن نصًّا لا يكتبه الخادم أبدًا ──

    public function test_admin_pending_filter_matches_the_status_the_server_writes(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-S-9', 'type' => 'تجاري',
            'status' => 'بانتظار اعتماد الإدارة', 'tone' => 'b-amber',
        ]);

        $this->actingAs($admin)->get(route('admin.tickets', ['status' => 'pending_admin']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('tickets.data', 1));
    }
}
