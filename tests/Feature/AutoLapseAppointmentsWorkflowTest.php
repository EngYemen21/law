<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\JourneyTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **حسم المواعيد الفائتة يمرّ بالمحرّك** (`appointment.lapse`) — والنتيجة المخزّنة كما كانت:
 * ما تشتقّه `liveState()` بعينه.
 */
class AutoLapseAppointmentsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function appointment(array $extra = []): Appointment
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Appointment::create(array_merge([
            'user_id' => $client->id, 'ext_id' => 'APT-'.uniqid(), 'type' => 'استشارة مرئية', 'ico' => 'video',
            'lawyer' => 'أ. سارة', 'day' => '—', 'time' => '—', 'duration_min' => 60, 'place' => 'اجتماع إلكتروني',
            'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up', 'starts_at' => now()->subHours(3),
        ], $extra));
    }

    public function test_a_missed_appointment_lapses_to_no_show_through_the_engine(): void
    {
        $a = $this->appointment();

        $this->artisan('appointments:auto-lapse')->assertExitCode(0);

        $a->refresh();
        $this->assertSame(['past', 'لم يحضر', 'b-red'], [$a->when_kind, $a->status, $a->tone]);
        $row = JourneyTransition::where('transition', 'appointment.lapse')->where('entity_id', $a->id)->sole();
        $this->assertSame(['مؤكد', 'لم يحضر'], [$row->from_state, $row->to_state]);
    }

    public function test_an_ended_session_lapses_to_attended(): void
    {
        $a = $this->appointment();
        Consult::create([
            'user_id' => $a->user_id, 'appointment_id' => $a->id, 'ref' => 'CN-'.uniqid(), 'subject' => 'نزاع',
            'channel' => 'مرئية', 'lawyer' => 'أ. سارة', 'day' => '—', 'time' => '—', 'when_label' => '—',
            'status' => 'منتهية', 'session' => 'منتهية',
        ]);

        $this->artisan('appointments:auto-lapse');

        $a->refresh();
        $this->assertSame(['past', 'تم الحضور', 'b-green'], [$a->when_kind, $a->status, $a->tone]);
    }

    /** الملغى المخزَّن يُحسم عرضاً فقط — لا انتقالَ بلا تغيّر حالة. */
    public function test_a_cancelled_appointment_is_settled_without_a_journey_row(): void
    {
        $a = $this->appointment(['status' => 'ملغي', 'tone' => 'b-red']);

        $this->artisan('appointments:auto-lapse');

        $a->refresh();
        $this->assertSame(['past', 'ملغي', 'b-grey'], [$a->when_kind, $a->status, $a->tone]);
        $this->assertSame(0, JourneyTransition::where('transition', 'appointment.lapse')->count());
    }

    /** الاقتراح غير المعتمد ليس موعداً فات. */
    public function test_a_pending_proposal_is_left_alone(): void
    {
        $a = $this->appointment(['status' => 'بانتظار الاعتماد', 'tone' => 'b-amber']);

        $this->artisan('appointments:auto-lapse');

        $this->assertSame(['up', 'بانتظار الاعتماد'], [$a->fresh()->when_kind, $a->fresh()->status]);
    }
}
