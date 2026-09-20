<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\ConsultBooked;
use App\Mail\ConsultReminderMail;
use App\Mail\MeetingLinkReady;
use App\Mail\MeetInviteMail;
use App\Models\Consult;
use App\Models\MeetRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **بريد العميل لا يحمل اسم المحامي كاملاً** (قرار المالك 2026-09-11، ثغرةٌ وُجدت 2026-09-20).
 *
 * شاشات العميل وبطاقاته تقنّع الاسم («سارة. ق»)، وأربعة قوالب بريدٍ كانت تطبع `$consult->lawyer`
 * الخام فيصل الاسم كاملاً إلى بريده. وبريدُ المحامي نفسه يبقى بالاسم الكامل — هو صاحبه.
 */
class LawyerNameInClientEmailsTest extends TestCase
{
    use RefreshDatabase;

    private const FULL = 'سارة القحطاني';

    private const SHORT = 'سارة. ق';

    private function consult(): Consult
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'عميل التجربة']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => self::FULL]);

        return Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-ML-1', 'subject' => 'نزاع تجاري',
            'channel' => 'مرئية', 'status' => 'بانتظار تحديد الموعد', 'session' => 'بانتظار الجلسة',
            'lawyer' => self::FULL, 'assigned_lawyer_id' => $lawyer->id,
            'day' => '2026-10-05', 'time' => '11:30 ص', 'when_label' => 'الاثنين 5 أكتوبر · 11:30 ص',
            'starts_at' => now()->addDay(), 'duration_min' => 30,
        ]);
    }

    /** @return array{0:string,1:string} نصّ بريد العميل · نصّ بريد المحامي */
    private function bothViews(Consult $consult): array
    {
        return [
            (new ConsultBooked($consult))->render(),
            (new ConsultBooked($consult, forLawyer: true))->render(),
        ];
    }

    public function test_the_booking_email_shortens_the_name_for_the_client_only(): void
    {
        [$toClient, $toLawyer] = $this->bothViews($this->consult());

        $this->assertStringContainsString(self::SHORT, $toClient);
        $this->assertStringNotContainsString(self::FULL, $toClient, 'وصل العميلَ اسم المحامي كاملاً.');

        // وبريد المحامي يخاطبه باسمه كاملاً — هو صاحبه
        $this->assertStringContainsString(self::FULL, $toLawyer);
    }

    public function test_the_reminder_email_shortens_the_name(): void
    {
        $body = (new ConsultReminderMail($this->consult(), 'نحو ٣٠ دقيقة'))->render();

        $this->assertStringContainsString(self::SHORT, $body);
        $this->assertStringNotContainsString(self::FULL, $body);
    }

    public function test_the_meeting_link_email_shortens_the_name_for_the_client(): void
    {
        $consult = $this->consult();

        $toClient = (new MeetingLinkReady($consult))->render();
        $this->assertStringNotContainsString(self::FULL, $toClient, 'وصل العميلَ اسم المحامي كاملاً.');
        $this->assertStringContainsString(self::SHORT, $toClient);

        $this->assertStringContainsString(self::FULL, (new MeetingLinkReady($consult, forLawyer: true))->render());
    }

    /** دعوة الاجتماع تُرسَل للعميل وحده (`MeetingController` و`MeetRequestController`). */
    public function test_the_meeting_invite_shortens_the_name(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => self::FULL]);
        $request = MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-ML-1', 'service' => 'مراجعة عقد',
            'type' => 'استشارة مرئية', 'day' => '2026-10-06', 'time' => '12:00 م',
            'duration_min' => 30, 'assigned_lawyer_id' => $lawyer->id, 'sent_by' => 'موظّف الاستقبال',
        ]);

        $body = (new MeetInviteMail($request->fresh()))->render();

        $this->assertStringContainsString(self::SHORT, $body);
        $this->assertStringNotContainsString(self::FULL, $body);
    }
}
