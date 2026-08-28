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
 * دعوة المكتب ← موافقة الإدارة (Zoom + اجتماع قادم مؤكَّد) ← تنفيذ الجلسة ← المحضر والملخص ← اعتماد الإدارة.
 */
class MeetingFlowTest extends TestCase
{
    use RefreshDatabase;

    // تأكيد حضور العميل أُلغي بقرار صاحب المنتج: الدعوة تُولَد مؤكَّدة ومنطق إنشاء الجلسة انتقل إلى App\Support\MeetInvitation — تغطيته في MeetInvitationConfirmedTest وZoomGapsTest.
    // (الاختبار السابق: test_foreign_client_cannot_confirm_invite) — محفوظ في تاريخ git

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
            'day' => '—', 'time' => '—', 'sent_by' => 'المكتب', 'sent_by_id' => $employee->id, 'stage' => 1,
        ]);

        // تنفيذ الجلسة (المرحلة 2) + الاجتماع «جارٍ» + علَم «قائم الآن» + بثّ لحظي لشاشة العميل
        Event::fake([MeetingStatusBroadcast::class]);
        $this->actingAs($employee)->post(route('employee.meetreqs.start', $req))->assertRedirect();
        $this->assertSame(MeetRequest::STAGE_EXECUTED, $req->fresh()->stage);
        $this->assertSame('جارٍ', $meeting->fresh()->status);
        $this->assertTrue($meeting->fresh()->isUpcoming());
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
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

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
        // «قادم» منذ الإنشاء: لا انتظار لتأكيد العميل
        $this->assertSame('قادم', $meeting->status);
        $this->assertSame($client->id, $meeting->user_id);
        $this->assertSame('عالية', $meeting->priority);
        $this->assertSame($lawyer->id, $meeting->assigned_lawyer_id);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());

        // تم إنشاء طلب دعوة مرتبط — مؤكَّد منذ الإنشاء (تأكيد العميل مُلغى)
        $req = MeetRequest::where('meeting_id', $meeting->id)->firstOrFail();
        // تُولَد مؤكَّدة: تأكيد العميل أُلغي (MeetInvitationConfirmedTest)
        $this->assertSame(MeetRequest::STAGE_CONFIRMED, $req->stage);

        // الدعوة تُولَد مؤكَّدة والاجتماع «قادم» منذ إنشائه (تأكيد العميل مُلغى)
        $meeting->refresh();
        $this->assertSame('قادم', $meeting->status);

        // ويظهر في قائمة المحامي المسؤول
        $this->actingAs($lawyer)->get(route('lawyer.meetings'))
            ->assertInertia(fn ($p) => $p->has('meetings', 1));
    }

    public function test_expired_invite_can_be_resent_with_new_date(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $req = MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-7500', 'service' => 'نزاع تجاري',
            'type' => 'استشارة مرئية', 'day' => now()->subWeek()->format('Y-m-d'), 'time' => '11:30',
            'sent_by' => 'المكتب', 'sent_by_id' => $employee->id, 'stage' => MeetRequest::STAGE_EXPIRED,
        ]);

        $newDay = now()->addWeek()->format('Y-m-d');
        $this->actingAs($employee)->post(route('employee.meetreqs.resend', $req), [
            'day' => $newDay, 'time' => '10:00',
        ])->assertRedirect();

        $req->refresh();
        // بوّابة النشر (2026-08-25): إعادة إرسال الموظف تعود لموافقة الإدارة — لا نشر ولا إشعار للعميل
        // (بوّابة الموافقة كاملة مغطّاة في MeetInvitationApprovalGateTest)
        $this->assertSame(MeetRequest::STAGE_SENT, $req->stage);
        $this->assertSame($newDay, $req->day);
        $this->assertSame('10:00', $req->time);
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count());
    }

    public function test_resend_rejected_for_non_expired_or_foreign_invite(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $owner = User::factory()->create(['role' => Role::Employee]);
        $intruder = User::factory()->create(['role' => Role::Employee]);
        $req = MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-7501', 'service' => 'خدمة',
            'type' => 'استشارة مرئية', 'day' => now()->addWeek()->format('Y-m-d'), 'time' => '11:30',
            'sent_by' => 'المكتب', 'sent_by_id' => $owner->id, 'stage' => MeetRequest::STAGE_SENT,
        ]);
        $payload = ['day' => now()->addWeek()->format('Y-m-d'), 'time' => '10:00'];

        // ليست منتهية الصلاحية ⇒ 422، وغير المُرسِل ممنوع 403 حتى لو انتهت
        $this->actingAs($owner)->post(route('employee.meetreqs.resend', $req), $payload)->assertStatus(422);
        $req->update(['stage' => MeetRequest::STAGE_EXPIRED]);
        $this->actingAs($intruder)->post(route('employee.meetreqs.resend', $req), $payload)->assertForbidden();
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

    public function test_meeting_links_are_in_platform_and_require_auth_redirection(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        $meeting = Meeting::create([
            'ref' => 'M-9900',
            'title' => 'جلسة سرية هامة',
            'status' => 'قادم',
            'when_label' => 'اليوم 10:00 ص',
            'user_id' => $client->id,
            'assigned_lawyer_id' => $lawyer->id,
            'meet_link' => 'https://zoom.us/j/123456789',
        ]);

        // روابط الدخول لا ترسل المستخدم لزووم الخارجي بل للغرفة المضمنة بالمنصة
        $this->assertSame(url('/meetingroom?ref=M-9900'), $meeting->joinLink($client));
        $this->assertSame(url('/lawyer/meetingroom?ref=M-9900'), $meeting->joinLink($lawyer));
        $this->assertSame(url('/employee/meetingroom?ref=M-9900'), $meeting->joinLink($employee));

        // روابط التبويبات الموجهة بالبريد
        $this->assertSame(url('/meetreqs'), $meeting->portalUrlFor($client));
        $this->assertSame(url('/lawyer/meetreqs'), $meeting->portalUrlFor($lawyer));
        $this->assertSame(url('/employee/meetreqs'), $meeting->portalUrlFor($employee));

        // الزائر غير المسجل يتم توجيهه لصفحة الدخول
        $this->get('/meetingroom?ref=M-9900')->assertRedirect('/login');
        $this->get('/meetreqs')->assertRedirect('/login');
        $this->get('/lawyer/meetreqs')->assertRedirect('/login');
    }
}
