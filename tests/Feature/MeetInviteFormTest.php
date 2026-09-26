<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فورم دعوة الاجتماع الاحترافي: محامٍ إلزاميّ (ولا مدّة — الاجتماع ينتهي حين يُنهى)، موعد مستقبلي فقط،
 * منع الحجز المزدوج للمحامي، نقطة التوفّر، وتمرير المحامي/المدة للاجتماع عند التأكيد.
 */
class MeetInviteFormTest extends TestCase
{
    use RefreshDatabase;

    private function payload(User $client, User $lawyer, array $over = []): array
    {
        return array_merge([
            'client_id' => $client->id, 'lawyer_id' => $lawyer->id,
            'type' => 'استشارة مرئية', 'service' => 'نزاع',
            'day' => now()->addWeek()->format('Y-m-d'), 'time' => '11:30', 'duration' => 60,
        ], $over);
    }

    /**
     * المحامي إلزاميّ — و«المدة» لم تعد حقلاً (قرار المالك 2026-09-26): الاجتماع ينتهي حين يُنهى،
     * والتعارض بمسافة الحجز من الإعدادات.
     */
    public function test_requires_lawyer_but_no_duration(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $emp = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($emp)->post(route('employee.meetreqs.store'), [
            'client_id' => $client->id, 'type' => 'استشارة مرئية',
            'day' => now()->addWeek()->format('Y-m-d'), 'time' => '11:30',
        ])->assertSessionHasErrors(['lawyer_id'])->assertSessionDoesntHaveErrors(['duration']);
    }

    public function test_rejects_past_day(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $emp = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($emp)->post(route('employee.meetreqs.store'),
            $this->payload($client, $lawyer, ['day' => now()->subDay()->format('Y-m-d')]))
            ->assertSessionHasErrors('day');
    }

    public function test_rejects_double_booking_and_allows_free_slot(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $emp = User::factory()->create(['role' => Role::Employee]);
        $day = now()->addWeek()->format('Y-m-d');

        // اجتماع قائم للمحامي 10:00–11:00
        Meeting::create([
            'ref' => 'M-BUSY', 'title' => 'اجتماع', 'when_label' => 'x', 'status' => 'قادم',
            'assigned_lawyer_id' => $lawyer->id, 'starts_at' => $day.' 10:00:00', 'dur' => '60 دقيقة',
        ]);

        // 10:30 يتعارض → مرفوض
        $this->actingAs($emp)->post(route('employee.meetreqs.store'),
            $this->payload($client, $lawyer, ['day' => $day, 'time' => '10:30']))
            ->assertSessionHasErrors('time');

        // 11:30 متاح → مقبول
        $this->actingAs($emp)->post(route('employee.meetreqs.store'),
            $this->payload($client, $lawyer, ['day' => $day, 'time' => '11:30']))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, MeetRequest::count());
    }

    public function test_availability_returns_busy_intervals(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $emp = User::factory()->create(['role' => Role::Employee]);
        $day = now()->addWeek()->format('Y-m-d');
        Meeting::create([
            'ref' => 'M-BUSY2', 'title' => 'اجتماع', 'when_label' => 'x', 'status' => 'قادم',
            'assigned_lawyer_id' => $lawyer->id, 'starts_at' => $day.' 14:00:00', 'dur' => '90 دقيقة',
        ]);

        $this->actingAs($emp)->getJson(route('employee.meetreqs.availability', ['lawyer_id' => $lawyer->id, 'day' => $day]))
            ->assertOk()
            ->assertJson(['busy' => [['14:00', '15:30']]]);
    }

    // تأكيد حضور العميل أُلغي بقرار صاحب المنتج: الدعوة تُولَد مؤكَّدة ومنطق إنشاء الجلسة انتقل إلى App\Support\MeetInvitation — تغطيته في MeetInvitationConfirmedTest وZoomGapsTest.
    // (الاختبار السابق: test_confirm_assigns_lawyer_and_duration_to_meeting) — محفوظ في تاريخ git
}
