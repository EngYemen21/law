<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\ConsultBooked;
use App\Mail\MeetingScheduledMail;
use App\Mail\MeetInviteMail;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Services\IcalendarService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_icalendar_service_generates_valid_ics(): void
    {
        $startsAt = now()->addDay()->setHour(10)->setMinute(0);
        $ics = IcalendarService::generate(
            uid: 'TEST-101',
            title: 'جلسة استشارة تجارية',
            description: "رابط الدخول: https://law-office.test/room\nالمستشار: أ. محمد",
            startsAt: $startsAt,
            durationMinutes: 45,
            locationUrl: 'https://law-office.test/room'
        );

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('VERSION:2.0', $ics);
        $this->assertStringContainsString('METHOD:REQUEST', $ics);
        $this->assertStringContainsString('SUMMARY:جلسة استشارة تجارية', $ics);
        $this->assertStringContainsString('TRIGGER:-PT15M', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
    }

    public function test_live_calendar_feed_returns_valid_response(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        Consult::create([
            'user_id' => $client->id,
            'assigned_lawyer_id' => $lawyer->id,
            'ref' => 'CN-8801',
            'subject' => 'استشارة عقارية',
            'type' => 'عقاري',
            'channel' => 'مرئية',
            'lawyer' => $lawyer->name,
            'starts_at' => now()->addDays(2)->setHour(14)->setMinute(0),
            'duration_minutes' => 60,
            'status' => 'مؤكدة',
        ]);

        Meeting::create([
            'user_id' => $client->id,
            'assigned_lawyer_id' => $lawyer->id,
            'ref' => 'M-9901',
            'title' => 'اجتماع مراجعة العقد',
            'type' => 'اجتماع مع عميل',
            'when_label' => 'الأربعاء 19 أغسطس',
            'starts_at' => now()->addDays(3)->setHour(11)->setMinute(0),
            'status' => 'قادم',
        ]);

        $validToken = $client->calendarToken();
        $feedUrl = route('calendar.feed', ['user' => $client->id, 'token' => $validToken]);

        $response = $this->get($feedUrl);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');
        $this->assertStringContainsString('BEGIN:VCALENDAR', $response->getContent());
        $this->assertStringContainsString('CN-8801', $response->getContent());
        $this->assertStringContainsString('M-9901', $response->getContent());

        // التحقق من حظر الوصول إذا كان الرمز غير صحيح
        $this->get(route('calendar.feed', ['user' => $client->id, 'token' => 'invalid-token']))
            ->assertStatus(403);
    }

    // بقرار المنتج (fe55756): رسائل البريد بلا أي مرفقات — الدعوة تصل نصاً والرابط داخل المحتوى
    public function test_mailables_have_no_file_attachments(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id,
            'ref' => 'CN-7701',
            'subject' => 'استشارة عمالية',
            'type' => 'عمالي',
            'channel' => 'مرئية',
            'lawyer' => 'أ. عبد العزيز',
            'day' => '2026-08-20',
            'time' => '10:00 ص',
            'status' => 'مؤكدة',
        ]);

        $mail = new ConsultBooked($consult);
        $this->assertSame([], $mail->attachments());

        $meetingMail = new MeetingScheduledMail('أحمد', 'اجتماع قضية', 'غداً 10:00 ص', 'https://law-office.test/room');
        $this->assertSame([], $meetingMail->attachments());

        $meetReq = MeetRequest::create([
            'user_id' => $client->id,
            'ref' => 'MR-6601',
            'service' => 'مراجعة لائحة',
            'type' => 'استشارة مرئية',
            'day' => '2026-08-22',
            'time' => '12:00 م',
            'sent_by' => 'خدمة العملاء',
        ]);
        $inviteMail = new MeetInviteMail($meetReq);
        $this->assertSame([], $inviteMail->attachments());
    }

    public function test_calendar_pages_render_with_feed_url(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $this->actingAs($client)->get(route('calendar'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('calendar')->has('events')->has('feedUrl')->has('webcalUrl'));

        $this->actingAs($lawyer)->get(route('lawyer.calendar'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('lawyer/calendar')->has('events')->has('feedUrl')->has('webcalUrl'));
    }

    /**
     * الموظف والإدارة لا يكونان assigned_lawyer_id أبداً، وكانت تغذيتهما تُفلتر به
     * فتعود بلا حدث واحد بينما شاشة تقويمهما تعرض أحداث المكتب كلّها وزرّ الاشتراك.
     */
    public function test_office_wide_staff_feed_carries_events_while_lawyer_stays_isolated(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ownerLawyer = User::factory()->create(['role' => Role::Lawyer]);
        $otherLawyer = User::factory()->create(['role' => Role::Lawyer]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->makeConsult($client, $ownerLawyer, 'CN-7001', now()->addDays(2));
        // خارج النافذة (+90 يوماً) — لا يُبنى تقويم اشتراك من أرشيف المكتب كلّه
        $this->makeConsult($client, $ownerLawyer, 'CN-7002', now()->addDays(200));

        foreach ([$employee, $admin] as $staff) {
            $body = $this->get(route('calendar.feed', ['user' => $staff->id, 'token' => $staff->calendarToken()]))
                ->assertOk()->getContent();

            $this->assertStringContainsString('CN-7001', $body);
            $this->assertStringNotContainsString('CN-7002', $body);
        }

        // العزل قائم: محامٍ غير مسنَد لا يرى استشارة زميله
        $lawyerBody = $this->get(route('calendar.feed', ['user' => $otherLawyer->id, 'token' => $otherLawyer->calendarToken()]))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('CN-7001', $lawyerBody);
    }

    private function makeConsult(User $client, User $lawyer, string $ref, CarbonInterface $startsAt): Consult
    {
        return Consult::create([
            'user_id' => $client->id,
            'assigned_lawyer_id' => $lawyer->id,
            'ref' => $ref,
            'subject' => 'استشارة مكتبية',
            'type' => 'عقاري',
            'channel' => 'مرئية',
            'lawyer' => $lawyer->name,
            'starts_at' => $startsAt->setHour(14)->setMinute(0),
            'duration_minutes' => 60,
            'status' => 'مؤكدة',
        ]);
    }
}
