<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\MeetInviteMail;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * فورم دعوة الاجتماع الاحترافي: محامٍ ومدة إلزاميان، موعد مستقبلي فقط،
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

    public function test_requires_lawyer_and_duration(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $emp = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($emp)->post(route('employee.meetreqs.store'), [
            'client_id' => $client->id, 'type' => 'استشارة مرئية',
            'day' => now()->addWeek()->format('Y-m-d'), 'time' => '11:30',
        ])->assertSessionHasErrors(['lawyer_id', 'duration']);
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

    public function test_sends_invite_email_to_client(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client, 'email' => 'cl@example.com']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $emp = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($emp)->post(route('employee.meetreqs.store'), $this->payload($client, $lawyer))->assertRedirect();

        Mail::assertQueued(MeetInviteMail::class, fn ($m) => $m->hasTo('cl@example.com'));
    }

    public function test_lawyer_sender_is_forced_as_responsible_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $self = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);

        // محامٍ يحاول إسناد محامٍ آخر → يُتجاهَل ويُسنَد هو
        $this->actingAs($self)->post(route('lawyer.meetreqs.store'), $this->payload($client, $other))->assertRedirect();

        $this->assertSame($self->id, MeetRequest::firstOrFail()->assigned_lawyer_id);
    }

    public function test_confirm_assigns_lawyer_and_duration_to_meeting(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $day = now()->addWeek()->format('Y-m-d');

        $req = MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-CONF', 'service' => 'نزاع', 'type' => 'استشارة مرئية',
            'day' => $day, 'time' => '12:00', 'sent_by' => 'المكتب', 'stage' => MeetRequest::STAGE_SENT,
            'assigned_lawyer_id' => $lawyer->id, 'duration_min' => 90,
        ]);

        $this->actingAs($client)->post(route('meetreqs.confirm', $req))->assertRedirect();

        $meeting = Meeting::where('user_id', $client->id)->firstOrFail();
        $this->assertSame($lawyer->id, $meeting->assigned_lawyer_id);
        $this->assertSame('90 دقيقة', $meeting->dur);
    }
}
