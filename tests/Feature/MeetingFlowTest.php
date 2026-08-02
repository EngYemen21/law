<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\MeetingStatusBroadcast;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * تحقّق من منظومة الاجتماعات:
 * دعوة المكتب ← تأكيد العميل (Zoom + اجتماع قادم) ← تنفيذ الجلسة ← المحضر والملخص ← اعتماد الإدارة.
 */
class MeetingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_sends_invite_and_client_is_notified(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee, 'name' => 'منيرة الحربي']);

        $this->actingAs($employee)->post(route('employee.meetreqs.store'), [
            'client_id' => $client->id,
            'service' => 'نزاع تجاري',
            'type' => 'استشارة مرئية',
            'day' => '2026-07-06',
            'time' => '11:30',
        ])->assertRedirect();

        $req = MeetRequest::firstOrFail();
        $this->assertSame($client->id, $req->user_id);
        $this->assertSame(MeetRequest::STAGE_SENT, $req->stage);
        $this->assertStringStartsWith('MR-', $req->ref);
        $this->assertStringContainsString('منيرة الحربي', $req->sent_by);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
    }

    public function test_invite_rejected_for_non_client_target(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($employee)->post(route('employee.meetreqs.store'), [
            'client_id' => $lawyer->id, 'type' => 'استشارة مرئية',
        ])->assertStatus(422);
    }

    public function test_client_confirms_invite_creating_meeting_with_link(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $req = MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-7001', 'service' => 'نزاع تجاري',
            'type' => 'استشارة مرئية', 'day' => 'الاثنين 29 يونيو', 'time' => '11:30 ص',
            'sent_by' => 'منيرة الحربي (خدمة العملاء)',
        ]);

        $this->actingAs($client)->post(route('meetreqs.confirm', $req))->assertRedirect();

        $req->refresh();
        $this->assertSame(MeetRequest::STAGE_CONFIRMED, $req->stage);
        $this->assertNotNull($req->meeting_id);

        $meeting = $req->meeting;
        $this->assertSame('قادم', $meeting->status);
        $this->assertSame($client->id, $meeting->user_id);
        // بلا مفاتيح Zoom: رابط داخلي احتياطي في البطاقة
        $this->assertStringContainsString('meet.salasel.sa', $req->fresh()->toCard()['meetLink']);

        // يظهر لدى العميل في «دعوات الاجتماعات» و«الاجتماعات»
        $this->actingAs($client)->get(route('meetreqs'))
            ->assertInertia(fn ($p) => $p->component('meetreqs')->has('requests', 1)->where('requests.0.stage', 1));
        $this->actingAs($client)->get(route('meetings'))
            ->assertInertia(fn ($p) => $p->has('meetings', 1)->where('meetings.0.up', true));
    }

    public function test_foreign_client_cannot_confirm_invite(): void
    {
        $owner = User::factory()->create(['role' => Role::Client]);
        $intruder = User::factory()->create(['role' => Role::Client]);
        $req = MeetRequest::create([
            'user_id' => $owner->id, 'ref' => 'MR-7002', 'service' => 'خدمة',
            'type' => 'استشارة مرئية', 'day' => '—', 'time' => '—', 'sent_by' => 'المكتب',
        ]);

        $this->actingAs($intruder)->post(route('meetreqs.confirm', $req))->assertForbidden();
        $this->assertSame(0, $req->fresh()->stage);
    }

    public function test_staff_starts_session_then_admin_approves(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $meeting = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-7100', 'title' => 'استشارة مرئية — نزاع تجاري',
            'when_label' => 'الاثنين · 11:30 ص', 'status' => 'قادم',
            'minutes' => 'محضر أولي', 'summary' => 'ملخص أولي',
        ]);
        $req = MeetRequest::create([
            'user_id' => $client->id, 'meeting_id' => $meeting->id, 'ref' => 'MR-7100',
            'service' => 'نزاع تجاري', 'type' => 'استشارة مرئية',
            'day' => '—', 'time' => '—', 'sent_by' => 'المكتب', 'stage' => 1,
        ]);

        // تنفيذ الجلسة (المرحلة 2) + الاجتماع «جارٍ» + علَم «قائم الآن» + بثّ لحظي لشاشة العميل
        Event::fake([MeetingStatusBroadcast::class]);
        $this->actingAs($employee)->post(route('employee.meetreqs.start', $req))->assertRedirect();
        $this->assertSame(MeetRequest::STAGE_EXECUTED, $req->fresh()->stage);
        $this->assertSame('جارٍ', $meeting->fresh()->status);
        $this->assertTrue($meeting->fresh()->is_up);
        Event::assertDispatched(MeetingStatusBroadcast::class);

        // إنهاء الاجتماع
        $this->actingAs($admin)->post(route('admin.meetings.end', $meeting), ['attend' => 88])->assertRedirect();
        $this->assertSame('منتهٍ', $meeting->fresh()->status);
        $this->assertSame(88, $meeting->fresh()->attend);

        // اعتماد الإدارة → مرحلة 3 + المحضر والملخص يظهران للعميل + إشعار
        $this->actingAs($admin)->post(route('admin.meetings.approve', $meeting))->assertRedirect();
        $meeting->refresh();
        $this->assertSame('معتمد', $meeting->approve);
        $this->assertSame(MeetRequest::STAGE_APPROVED, $req->fresh()->stage);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());

        $card = $meeting->toCard();
        $this->assertSame('محضر أولي', $card['minutes']);
        $this->assertSame('ملخص أولي', $card['summary']);
    }

    public function test_lawyer_saves_summary_and_minutes(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $meeting = Meeting::create([
            'ref' => 'M-7200', 'title' => 'اجتماع فريق قضية', 'when_label' => 'اليوم · 09:00 ص',
            'assigned_lawyer_id' => $lawyer->id,
        ]);

        $this->actingAs($lawyer)->post(route('lawyer.meetings.summary', $meeting), ['summary' => 'ملخص محفوظ'])->assertRedirect();
        $this->actingAs($lawyer)->post(route('lawyer.meetings.minutes', $meeting), ['minutes' => 'محضر محفوظ'])->assertRedirect();

        $meeting->refresh();
        $this->assertSame('ملخص محفوظ', $meeting->summary);
        $this->assertSame('محضر محفوظ', $meeting->minutes);

        // قبل الاعتماد لا يظهر المحضر/الملخص للعميل
        $card = $meeting->toCard();
        $this->assertNull($card['minutes']);
        $this->assertNull($card['summary']);
    }

    public function test_lawyer_cannot_access_unassigned_meeting(): void
    {
        $lawyerA = User::factory()->create(['role' => Role::Lawyer]);
        $lawyerB = User::factory()->create(['role' => Role::Lawyer]);
        $meeting = Meeting::create([
            'ref' => 'M-7400', 'title' => 'اجتماع سرّي', 'when_label' => 'اليوم', 'status' => 'قادم',
            'assigned_lawyer_id' => $lawyerA->id, 'decisions' => ['متابعة'],
        ]);

        // القائمة لا تُظهر اجتماع محامٍ آخر
        $this->actingAs($lawyerB)->get(route('lawyer.meetings'))
            ->assertInertia(fn ($p) => $p->has('meetings', 0));
        // الوصول المباشر والإجراءات ممنوعة (تكشف hostLink/الملخص)
        $this->actingAs($lawyerB)->get('/lawyer/meeting?id=M-7400')->assertForbidden();
        $this->actingAs($lawyerB)->post(route('lawyer.meetings.summary', $meeting), ['summary' => 'x'])->assertForbidden();
        $this->actingAs($lawyerB)->post(route('lawyer.meetings.tasks', $meeting))->assertForbidden();

        // المحامي المسند يصل
        $this->actingAs($lawyerA)->get('/lawyer/meeting?id=M-7400')->assertOk();
    }

    public function test_admin_creates_meeting_linked_to_client(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);

        $this->actingAs($admin)->post(route('admin.meetings.store'), [
            'title' => 'اجتماع مراجعة العقد',
            'type' => 'اجتماع مع عميل',
            'priority' => 'عالية',
            'conf' => 'سري',
            'dur' => '45 دقيقة',
            'client_id' => $client->id,
            'lawyer_id' => $lawyer->id,
            'day' => '2026-07-08',
            'time' => '10:00',
        ])->assertRedirect();

        $meeting = Meeting::firstOrFail();
        $this->assertSame('قادم', $meeting->status);
        $this->assertSame($client->id, $meeting->user_id);
        $this->assertSame('عالية', $meeting->priority);
        $this->assertNotEmpty($meeting->before_items);
        // المحامي المسؤول وفرعه مختومان → يظهر في قائمته
        $this->assertSame($lawyer->id, $meeting->assigned_lawyer_id);
        $this->assertSame('فرع الرياض', $meeting->branch);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());

        // ويظهر في قائمة المحامي المسؤول
        $this->actingAs($lawyer)->get(route('lawyer.meetings'))
            ->assertInertia(fn ($p) => $p->has('meetings', 1));
    }

    public function test_meeting_pages_render_with_real_data(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        Meeting::create(['ref' => 'M-7300', 'title' => 'اجتماع', 'when_label' => 'اليوم', 'status' => 'منتهٍ', 'assigned_lawyer_id' => $lawyer->id]);

        $this->actingAs($lawyer)->get(route('lawyer.meetings'))
            ->assertInertia(fn ($p) => $p->component('lawyer/meetings')->has('meetings', 1));
        $this->actingAs($lawyer)->get('/lawyer/meeting?id=M-7300')
            ->assertInertia(fn ($p) => $p->component('lawyer/meeting')->where('meeting.id', 'M-7300'));

        $this->actingAs($admin)->get(route('admin.meetmgmt'))
            ->assertInertia(fn ($p) => $p->component('admin/meetmgmt')->has('meetings', 1)->has('clients'));
        $this->actingAs($admin)->get(route('admin.meetlog'))
            ->assertInertia(fn ($p) => $p->component('admin/meetlog')->has('meetings', 1));
        $this->actingAs($admin)->get(route('admin.meetreports'))
            ->assertInertia(fn ($p) => $p->component('admin/meetreports')->has('meetings', 1));
        $this->actingAs($admin)->get(route('admin.meetings'))
            ->assertInertia(fn ($p) => $p->component('admin/meetings')->has('meetings', 1));
    }
}
