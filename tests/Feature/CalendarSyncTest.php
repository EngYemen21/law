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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_icalendar_service_generates_valid_ics_and_google_url(): void
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

        $gcalUrl = IcalendarService::googleUrl(
            title: 'جلسة استشارة',
            details: 'تفاصيل الموعد',
            startsAt: $startsAt,
            durationMinutes: 45,
            locationUrl: 'https://law-office.test/room'
        );

        $this->assertStringContainsString('https://calendar.google.com/calendar/render', $gcalUrl);
        $this->assertStringContainsString('action=TEMPLATE', $gcalUrl);
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

    public function test_google_schema_json_ld_generates_valid_script_tag(): void
    {
        $startsAt = now()->addDays(2)->setHour(16)->setMinute(0);
        $schema = IcalendarService::googleSchemaJsonLd(
            reservationNumber: 'RSV-999',
            recipientName: 'خالد عبدالله',
            title: 'استشارة تجارية',
            description: 'تفاصيل الاستشارة',
            startsAt: $startsAt,
            durationMinutes: 45,
            locationUrl: 'https://law-office.test/room'
        );

        $this->assertStringStartsWith('<script type="application/ld+json">', $schema);
        $this->assertStringEndsWith('</script>', $schema);
        $this->assertStringContainsString('EventReservation', $schema);
        $this->assertStringContainsString('RSV-999', $schema);
        $this->assertStringContainsString('خالد عبدالله', $schema);
    }
}
