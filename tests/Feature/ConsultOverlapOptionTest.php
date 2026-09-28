<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\HearingStatus;
use App\Enums\Role;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Setting;
use App\Models\User;
use App\Support\ConsultAppointments;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **خيار «السماح بحجز استشارةٍ لمحامٍ مشغول»** (`consult_allow_overlap`، قرار المالك 2026-09-28).
 *
 * القرار من موضعٍ واحد (`ConsultBooking::conflictVerdict`): «لا» يرفض كما كان، و«نعم» يقبل بتنبيه،
 * وجلسة المحكمة ترفض دائماً. ولا يمسّ الإسنادَ التلقائيّ ولا دعوات الاجتماعات.
 */
class ConsultOverlapOptionTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    private CarbonInterface $at;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at = now()->addDays(2)->setTime(10, 0);
    }

    private function lawyer(): User
    {
        return User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);
    }

    private function meetingFor(User $lawyer): void
    {
        Meeting::create([
            'user_id' => null, 'ref' => 'M-OV-'.uniqid(), 'title' => 'اجتماع داخلي', 'when_label' => 'x',
            'status' => 'قادم', 'assigned_lawyer_id' => $lawyer->id, 'starts_at' => $this->at, 'dur' => '60 دقيقة',
        ]);
    }

    private function hearingFor(User $lawyer): void
    {
        $case = LegalCase::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name, 'number' => 'CASE-OV-'.uniqid(),
            'title' => 'دعوى', 'type' => 'تجاري', 'status' => 'منظورة', 'tone' => 'b-blue', 'update_text' => '—',
        ]);
        CaseHearing::create([
            'case_id' => $case->id, 'title' => 'جلسة', 'day' => $this->at->format('Y-m-d'), 'time' => '10:00',
            'starts_at' => $this->at, 'court' => 'المحكمة', 'status' => HearingStatus::Scheduled->value,
        ]);
    }

    private function paidConsult()
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return $this->requestPricedAndPaid($client, $this->ticketWithApprovedOpinion($client, ['status' => 'بانتظار حجز الاستشارة']), 'video');
    }

    private function allowOverlap(): void
    {
        $this->actingAs($this->journeyAdmin())->post(route('admin.settings.update'), ['consult_allow_overlap' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('1', Setting::get('consult_allow_overlap'));
    }

    public function test_off_by_default_the_busy_lawyer_is_refused_as_before(): void
    {
        $lawyer = $this->lawyer();
        $this->meetingFor($lawyer);

        $this->adminPublishes($this->paidConsult(), ['date' => $this->at->toDateString(), 'time' => '10:00', 'lawyer_id' => $lawyer->id])
            ->assertStatus(422)->assertJsonPath('errors.time.0', 'المحامي مشغول في هذا الوقت — اختر وقتاً آخر أو محامياً مختلفاً.');
    }

    public function test_on_the_booking_is_accepted_with_a_notice_and_recorded(): void
    {
        $lawyer = $this->lawyer();
        $this->meetingFor($lawyer);
        $this->allowOverlap();
        $consult = $this->paidConsult();

        $this->adminPublishes($consult, ['date' => $this->at->toDateString(), 'time' => '10:00', 'lawyer_id' => $lawyer->id])
            ->assertOk()->assertJsonPath('message', fn (string $m) => str_ends_with($m, ConsultBooking::OVERLAP_NOTICE));

        $this->assertSame($lawyer->id, $consult->fresh()->assigned_lawyer_id);
        $this->assertDatabaseHas('journey_transitions', ['entity_id' => $consult->id, 'transition' => 'consult.publish-appointment']);
    }

    public function test_a_court_hearing_refuses_even_when_overlap_is_allowed(): void
    {
        $lawyer = $this->lawyer();
        $this->hearingFor($lawyer);
        $this->allowOverlap();

        $this->adminPublishes($this->paidConsult(), ['date' => $this->at->toDateString(), 'time' => '10:00', 'lawyer_id' => $lawyer->id])
            ->assertStatus(422)->assertJsonPath('errors.time.0', 'للمحامي جلسة محكمة في هذا الوقت — اختر وقتاً آخر أو محامياً مختلفاً.');
    }

    public function test_a_free_booking_carries_no_notice_and_approving_a_proposal_does_not_clash_with_itself(): void
    {
        $lawyer = $this->lawyer();
        $consult = $this->paidConsult();

        $this->employeeProposes($consult, ['date' => $this->at->toDateString(), 'time' => '10:00', 'lawyer_id' => $lawyer->id])
            ->assertOk()->assertJsonPath('message', fn (string $m) => ! str_contains($m, ConsultBooking::OVERLAP_NOTICE));

        // الاقتراح يشغل وقته — واعتماده لا يُعدّ تعارضاً (الخيار «لا»)
        $this->actingAs($this->journeyAdmin())->post(route('admin.consults.appointment.approve', $consult))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash', fn (string $m) => ! str_contains($m, ConsultBooking::OVERLAP_NOTICE));
    }

    public function test_auto_assignment_still_picks_a_free_lawyer(): void
    {
        $busy = $this->lawyer();
        $free = $this->lawyer();
        $this->meetingFor($busy);
        $this->allowOverlap();

        $this->assertSame($free->id, LawyerAvailability::assignLawyer('القضايا التجارية', null, Carbon::parse($this->at))?->id);
    }

    public function test_slots_mark_what_can_never_be_overridden(): void
    {
        $inMeeting = $this->lawyer();
        $inCourt = $this->lawyer();
        $this->meetingFor($inMeeting);
        $this->hearingFor($inCourt);

        $meeting = collect(LawyerAvailability::slotsFor($inMeeting->id, $this->at->toDateString()))->keyBy('time')['10:00'];
        $court = collect(LawyerAvailability::slotsFor($inCourt->id, $this->at->toDateString()))->keyBy('time')['10:00'];

        $this->assertSame(['taken' => true, 'hard' => false], ['taken' => $meeting['taken'], 'hard' => $meeting['hard']]);
        $this->assertSame(['taken' => true, 'hard' => true], ['taken' => $court['taken'], 'hard' => $court['hard']]);
    }

    public function test_the_option_reaches_the_booking_screens(): void
    {
        $this->assertStringContainsString('allowTaken={allowOverlap}', (string) file_get_contents(resource_path('js/pages/employee/schedule.tsx')));
        $this->assertStringContainsString('allowTaken={allowOverlap}', (string) file_get_contents(resource_path('js/pages/employee/ticketchat.tsx')));
    }

    /** الحكم يعود من الانتقال نفسه (`ScheduledConsult`) — لا قراءةٌ لاحقة لسجلّ الرحلة. */
    public function test_the_booking_returns_whether_it_overlapped(): void
    {
        $busy = $this->lawyer();
        $free = $this->lawyer();
        $this->meetingFor($busy);
        $this->allowOverlap();
        $input = fn (User $l) => ['date' => $this->at->toDateString(), 'time' => '10:00', 'lawyer_id' => $l->id];

        $over = ConsultAppointments::publish($this->paidConsult(), $this->journeyAdmin(), $input($busy));
        $clear = ConsultAppointments::publish($this->paidConsult(), $this->journeyAdmin(), $input($free));

        $this->assertTrue($over->overlap);
        $this->assertSame(' '.ConsultBooking::OVERLAP_NOTICE, $over->notice());
        $this->assertFalse($clear->overlap);
        $this->assertSame('', $clear->notice());
        $this->assertSame($busy->id, $over->consult->assigned_lawyer_id);
    }
}
