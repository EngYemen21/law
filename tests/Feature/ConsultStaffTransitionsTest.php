<?php

namespace Tests\Feature;

use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Consult\ApproveConsultSummary;
use App\Domain\Journey\Transitions\Consult\EndSession;
use App\Domain\Journey\Transitions\Consult\ZoomSessionStarted;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\JourneyTransition;
use App\Models\User;
use App\Services\Ai\AiReviewOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **كتّاب حالة الاستشارة القدامى صاروا انتقالات** — طلب المستندات، واعتماد التحليل، وختم الجلسة
 * (الطاقم وZoom)، وبدء Zoom، واعتماد الملخّص النهائيّ. النتيجة المخزّنة كما كانت، ويُقيَّد السجلّ.
 */
class ConsultStaffTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private function consult(array $extra = []): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create(array_merge([
            'user_id' => $client->id, 'ref' => 'CN-'.uniqid(), 'subject' => 'نزاع تجاري', 'channel' => 'مرئية',
            'lawyer' => 'أ. سارة', 'day' => '—', 'time' => '—', 'when_label' => '—', 'status' => 'جديدة',
        ], $extra));
    }

    private function rows(Consult $consult, string $name): int
    {
        return JourneyTransition::where('entity_type', 'Consult')->where('entity_id', $consult->id)->where('transition', $name)->count();
    }

    public function test_requesting_docs_moves_to_awaiting_data_through_the_engine(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $consult = $this->consult(['status' => 'جاهزة للمحامي', 'missing' => ['هوية']]);

        $this->actingAs($employee)->post(route('employee.consults.reqdocs', $consult), ['docs' => 'عقد التوريد'])->assertRedirect();

        $consult->refresh();
        $this->assertSame('بانتظار استكمال البيانات', $consult->status);
        $this->assertSame(['هوية', 'عقد التوريد'], $consult->missing);
        $this->assertSame('بانتظار استكمال البيانات', $consult->audit[0]['after']);
        $this->assertSame(1, $this->rows($consult, 'consult.request_docs'));
    }

    public function test_requesting_docs_in_the_booking_cycle_keeps_its_message(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $consult = $this->consult(['status' => 'بانتظار السداد']);

        $this->actingAs($employee)->post(route('employee.consults.reqdocs', $consult), ['docs' => 'عقد التوريد'])->assertStatus(422);

        $this->assertSame('بانتظار السداد', $consult->fresh()->status);
    }

    public function test_approving_analysis_is_a_transition_and_refuses_loudly_otherwise(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $consult = $this->consult(['status' => 'بانتظار اعتماد الموظف']);

        $this->actingAs($employee)->post(route('employee.consults.approve', $consult))->assertRedirect();
        // كانت الضغطة الثانية تعود نجاحاً صامتاً فتعرض الشاشة «اعتُمد التحليل» — الآن 422 بالسبب
        $this->actingAs($employee)->post(route('employee.consults.approve', $consult))->assertStatus(422);

        $this->assertSame('جاهزة للمحامي', $consult->fresh()->status);
        $this->assertSame(1, $this->rows($consult, 'consult.approve_analysis'), 'الضغطة الثانية لا انتقال لها');
    }

    public function test_staff_end_seals_the_session_and_settles_the_appointment(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $appointment = Appointment::create([
            'user_id' => User::factory()->create()->id, 'ext_id' => 'APT-'.uniqid(), 'type' => 'استشارة مرئية', 'ico' => 'video',
            'lawyer' => 'أ. سارة', 'day' => '—', 'time' => '—', 'place' => 'اجتماع إلكتروني', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up',
        ]);
        $consult = $this->consult(['session' => 'جلسة جارية', 'status' => 'قيد الاستشارة', 'appointment_id' => $appointment->id]);

        $this->actingAs($employee)->post(route('employee.consults.end', $consult), ['notes' => 'تدوين', 'duration' => '10:00'])->assertRedirect();

        $consult->refresh();
        $this->assertSame(['منتهية', 'منتهية', '10:00', 'تدوين'], [$consult->session, $consult->status, $consult->duration_label, $consult->session_notes]);
        $appointment->refresh();
        $this->assertSame(['past', 'تم الحضور', 'b-green'], [$appointment->when_kind, $appointment->status, $appointment->tone]);
        $this->assertSame(1, $this->rows($consult, 'consult.end'));
    }

    /** Zoom دليلُ انعقاد: يبدأ ويختم من أيّ جلسةٍ لم تُختم — ولا يُحيي المختومة. */
    public function test_zoom_transitions_accept_any_unsealed_session_and_refuse_a_sealed_one(): void
    {
        $consult = $this->consult(['session' => 'لم تُعقد', 'status' => 'لم يحضر']);

        Workflow::run(new ZoomSessionStarted, $consult);
        $this->assertSame(['جلسة جارية', 'قيد الاستشارة'], [$consult->session, $consult->status]);

        Workflow::run(new EndSession, $consult, null, ['source' => 'zoom']);
        $this->assertSame(['منتهية', 'منتهية'], [$consult->session, $consult->status]);

        $this->expectException(TransitionDenied::class);
        Workflow::run(new ZoomSessionStarted, $consult);
    }

    public function test_final_summary_approval_goes_through_the_engine_and_keeps_its_audit_entry(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->consult(['session' => 'منتهية', 'status' => 'منتهية', 'summary' => 'ملخّص الجلسة']);

        $this->assertTrue(AiReviewOutcome::approveConsultSummary($consult, $admin));
        $this->assertFalse(AiReviewOutcome::approveConsultSummary($consult, $admin), 'الاعتماد الثاني صامت كما كان');

        $consult->refresh();
        $this->assertNotNull($consult->summary_approved_at);
        $this->assertSame($admin->id, $consult->summary_approved_by);
        $this->assertNotNull($consult->summary_lawyer_approved_at);
        $this->assertSame('اعتماد ملخّص الاستشارة', $consult->audit[0]['field']);
        $this->assertSame(1, $this->rows($consult, 'consult.approve_summary'));
    }

    public function test_summary_approval_refuses_an_unheld_session(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->consult(['session' => 'بانتظار الجلسة', 'summary' => 'نصّ']);

        $this->assertFalse(AiReviewOutcome::approveConsultSummary($consult, $admin));

        $this->expectException(TransitionDenied::class);
        Workflow::run(new ApproveConsultSummary, $consult, $admin);
    }
}
