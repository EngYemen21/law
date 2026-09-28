<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Enums\Role;
use App\Models\Meeting;
use App\Models\User;
use App\Support\ChannelAccess;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * **المشاركون من الكادر حساباتٌ لها ما تراه** (قرار المالك 2026-09-28).
 *
 * ثبت بالاختبار قبل الإصلاح: المشارك يُحفظ نصّاً ويُطابَق بالاسم، والمحامي المشارك يُشعَر «متاح في لوحتك»
 * ولا يجده في قائمته، وفتحه مباشرةً 404. الآن: `meeting_participants`، ويرى المشارك الاجتماع ويدخله
 * (`Meeting::visibleToLawyer`/`involves`) — وأفعال الإدارة (بدء، إنهاء، إلغاء…) للمسؤول وحده.
 */
class MeetingParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $participant;

    private User $employee;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'المسؤول']);
        $this->participant = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'المشارك']);
        $this->employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active', 'name' => 'موظف مشارك']);
        $this->outsider = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'محامٍ آخر']);
    }

    private function createMeeting(array $participantIds): Meeting
    {
        $this->actingAs(User::factory()->create(['role' => Role::Admin, 'status' => 'active']))
            ->post(route('admin.meetings.store'), [
                'title' => 'اجتماع فريق', 'type' => 'اجتماع داخلي', 'day' => now()->addDays(2)->toDateString(), 'time' => '10:00',
                'lawyer_id' => $this->owner->id, 'participant_ids' => $participantIds,
            ])->assertSessionHasNoErrors();

        return Meeting::latest('id')->firstOrFail();
    }

    public function test_participants_are_saved_as_accounts_and_notified(): void
    {
        // المسؤول في القائمة لا يُحفظ «مشاركاً» — له صفته
        $meeting = $this->createMeeting([$this->participant->id, $this->employee->id, $this->owner->id]);

        $this->assertEqualsCanonicalizing([$this->participant->id, $this->employee->id], $meeting->participantUsers->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['المشارك', 'موظف مشارك'], explode('، ', (string) $meeting->participantsLabel()));
        foreach ([$this->participant, $this->employee] as $u) {
            $this->assertDatabaseHas('user_notifications', ['user_id' => $u->id]);
        }
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $this->outsider->id]);
    }

    public function test_a_client_cannot_be_added_as_a_staff_participant(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs(User::factory()->create(['role' => Role::Admin, 'status' => 'active']))
            ->post(route('admin.meetings.store'), [
                'title' => 'اجتماع', 'type' => 'اجتماع داخلي', 'day' => now()->addDays(2)->toDateString(), 'time' => '10:00',
                'participant_ids' => [$client->id],
            ])->assertSessionHasErrors('participant_ids.0');
    }

    public function test_the_participating_lawyer_sees_and_opens_it_but_cannot_manage_it(): void
    {
        $meeting = $this->createMeeting([$this->participant->id]);

        $titles = fn (User $u) => collect($this->actingAs($u)->get(route('lawyer.meetings'))->viewData('page')['props']['meetings'])->pluck('id')->all(); // `toFullCard` يضع رقم الاجتماع في `id`
        $this->assertContains($meeting->ref, $titles($this->participant), 'المشارك لا يجد الاجتماع في قائمته');
        $this->assertNotContains($meeting->ref, $titles($this->outsider));

        $this->actingAs($this->participant)->get(route('lawyer.meeting', ['id' => $meeting->ref]))->assertOk();
        $this->assertPageRefused($this->actingAs($this->outsider)->get(route('lawyer.meeting', ['id' => $meeting->ref])));

        // الإدارة للمسؤول وحده
        $this->actingAs($this->participant)->post(route('lawyer.meetings.start', $meeting))->assertForbidden();

        // البثّ والغرفة من المصدر نفسه
        $this->assertTrue(ChannelAccess::staffCanSee($this->participant, $meeting));
        $this->assertFalse(ChannelAccess::staffCanSee($this->outsider, $meeting));
    }

    public function test_the_participating_lawyers_calendar_includes_it(): void
    {
        $meeting = $this->createMeeting([$this->participant->id]);

        $this->assertSame([$meeting->id], Meeting::visibleToLawyer($this->participant->id)->pluck('id')->all());
        $this->assertSame([], Meeting::visibleToLawyer($this->outsider->id)->pluck('id')->all());
        $this->assertStringContainsString('Meeting::visibleToLawyer($lawyerId)', (string) file_get_contents(app_path('Http/Controllers/Lawyer/CalendarController.php')));
        $this->assertStringContainsString('$meetingQuery->visibleToLawyer($user->id)', (string) file_get_contents(app_path('Services/IcalendarService.php')));
    }

    public function test_the_modal_sends_participant_ids(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/admin/meetmgmt.tsx'));
        $this->assertStringContainsString('participant_ids: participants,', $page);
        $this->assertStringNotContainsString("participants.join('، ')", $page);
    }

    public function test_a_participant_is_busy_during_the_meeting_like_the_responsible_lawyer(): void
    {
        $meeting = $this->createMeeting([$this->participant->id, $this->employee->id]);
        $at = Carbon::parse($meeting->starts_at);

        // المحرّك الواحد: المشارك مشغولٌ في وقت الاجتماع — فلا يُحجز له موعدٌ فوقه
        $this->assertTrue(LawyerAvailability::isBusy($this->participant->id, $at), 'المشارك يظهر متاحاً وقت اجتماعه');
        $this->assertTrue(LawyerAvailability::isBusy($this->owner->id, $at));
        $this->assertFalse(LawyerAvailability::isBusy($this->outsider->id, $at));
        $this->assertEqualsCanonicalizing(
            [$this->participant->id, $this->employee->id],
            LawyerAvailability::busyAmong([$this->participant->id, $this->employee->id, $this->outsider->id], $at),
        );

        // والملغى يحرّر وقتهم
        $meeting->update(['status' => MeetingStatus::Cancelled->value]);
        $this->assertFalse(LawyerAvailability::isBusy($this->participant->id, $at));
    }

    public function test_a_busy_participant_is_refused_by_name(): void
    {
        $this->createMeeting([$this->participant->id]);

        // اجتماعٌ ثانٍ في الوقت نفسه بمسؤولٍ آخر والمشارك نفسه ⇒ رفضٌ باسمه ولا اجتماع
        $count = Meeting::count();
        $this->actingAs(User::factory()->create(['role' => Role::Admin, 'status' => 'active']))
            ->post(route('admin.meetings.store'), [
                'title' => 'اجتماع آخر', 'type' => 'اجتماع داخلي', 'day' => now()->addDays(2)->toDateString(), 'time' => '10:00',
                'lawyer_id' => $this->outsider->id, 'participant_ids' => [$this->participant->id, $this->employee->id],
            ])->assertSessionHasErrors(['participant_ids' => 'مشغولٌ في هذا الوقت: المشارك — أزِله من المشاركين أو اختر وقتاً آخر.']);
        $this->assertSame($count, Meeting::count());
    }
}
