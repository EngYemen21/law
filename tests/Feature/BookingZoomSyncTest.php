<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Support\Booking\BookingMoved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **Zoom يتبع الموعد.** ثلاثة مسارات كانت تُحرّك الوقت وتترك الاجتماع خلفها.
 *
 * وأوضحُها دلالةً إعادة إرسال الدعوة: تعليقٌ في `MeetInvitation` يقول إنها «كانت
 * تُبقي الاجتماع على موعده القديم» — أي أن أحداً أصلح **جانب قاعدة البيانات** وظنّ
 * العطل تامّاً، وبقي Zoom على الموعد القديم. فالعطل الحقيقيّ ليس نسياناً واحداً بل
 * أن اللوازم الأربعة لتحريك موعدٍ كانت متفرّقةً على المسارات.
 *
 * ولذلك يحرس هذا الملفّ **الفعل الواحد** (`BookingMoved`) لا كلّ مسارٍ على حدة:
 * ما دام كلّ مسارٍ يناديه، فإضافةُ لازمٍ خامس تصل الجميع.
 */
class BookingZoomSyncTest extends TestCase
{
    use RefreshDatabase;

    private function fakeZoom(): void
    {
        config([
            'services.zoom.account_id' => 'a',
            'services.zoom.client_id' => 'b',
            'services.zoom.client_secret' => 'c',
        ]);

        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'api.zoom.us/v2/users/me/meetings' => Http::response(
                ['id' => 9111, 'join_url' => 'j', 'start_url' => 's', 'password' => 'p']
            ),
            'api.zoom.us/v2/*' => Http::response([], 204),
        ]);
    }

    /** @return array{0:User,1:User,2:User} عميل · محامٍ · إدارة */
    private function cast(): array
    {
        return [
            User::factory()->create(['role' => Role::Client]),
            User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']),
            User::factory()->create(['role' => Role::Admin]),
        ];
    }

    /**
     * **الحارس الأثمن:** إعادة إرسال دعوةٍ بموعدٍ جديد تُخبر Zoom.
     *
     * كان `$zoom` فارغاً في فرع الاجتماع القائم (لأن `meet_id` موجود)، فلا يُنادى
     * `updateMeeting` — فالبوّابة والبريد على الموعد الجديد وZoom على القديم.
     */
    public function test_resending_an_invitation_patches_zoom_with_the_new_time(): void
    {
        $this->fakeZoom();
        [$client, $lawyer, $admin] = $this->cast();
        $day = now()->addDays(3)->toDateString();

        $this->actingAs($admin)->post('/admin/meetreqs', [
            'client_id' => $client->id, 'lawyer_id' => $lawyer->id,
            'type' => 'استشارة مرئية', 'day' => $day, 'time' => '10:00', 'duration' => 60,
        ])->assertRedirect();

        $req = MeetRequest::latest('id')->firstOrFail();
        $this->assertNotNull($req->meeting_id);

        $req->update(['stage' => MeetRequest::STAGE_EXPIRED]);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'api.zoom.us/v2/*' => Http::response([], 204),
        ]);

        $newDay = now()->addDays(9)->toDateString();
        $this->actingAs($admin)->post("/admin/meetreqs/{$req->id}/resend", [
            'day' => $newDay, 'time' => '15:00',
        ])->assertRedirect();

        $meeting = Meeting::find($req->fresh()->meeting_id);
        $this->assertSame($newDay, $meeting->starts_at?->toDateString(), 'قاعدة البيانات تحرّكت');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/meetings/9111')
            && ($r->data()['start_time'] ?? null) === $newDay.'T15:00:00');
    }

    /** وإعادة جدولة الاجتماع تُرسل **مدّته الحقيقيّة** لا ستّين مثبَّتة. */
    public function test_rescheduling_sends_the_real_duration_not_a_hardcoded_60(): void
    {
        $this->fakeZoom();
        [, , $admin] = $this->cast();

        $meeting = Meeting::create([
            'user_id' => $admin->id, 'ref' => 'M-DUR-'.uniqid(), 'title' => 'اجتماع طويل',
            'when_label' => 'اليوم · 10:00', 'type' => 'اجتماع', 'status' => 'قادم',
            'dur' => '90 دقيقة', 'meet_id' => '9222', 'starts_at' => now()->addDay(),
        ]);
        $this->assertSame(90, $meeting->durationMinutes());

        $day = now()->addDays(4)->toDateString();
        $this->actingAs($admin)->post("/admin/meetings/{$meeting->id}/reschedule", [
            'day' => $day, 'time' => '11:00',
        ])->assertRedirect();

        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/meetings/9222')
            && ($r->data()['duration'] ?? null) === 90);
    }

    /** وإعادة جدولة الاستشارة **تحذف** اجتماعها بدل أن تتركه يتيماً. */
    public function test_rescheduling_a_consult_deletes_its_zoom_meeting(): void
    {
        $this->fakeZoom();
        [$client, , $admin] = $this->cast();

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-ORPH-'.uniqid(), 'subject' => 'نزاع',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'جديدة',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-blue', 'lawyer' => 'مستشار',
            'starts_at' => now()->addDays(2), 'meet_id' => '9333', 'meet_link' => 'j',
            'link_released_at' => now(), 'reminder_24h_sent_at' => now(),
        ]);

        $this->actingAs($admin)->post("/admin/consults/{$consult->id}/reschedule")->assertRedirect();

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/meetings/9333'));

        $consult->refresh();
        $this->assertNull($consult->meet_id);
        $this->assertNull($consult->link_released_at, 'وختم الإطلاق يُصفَّر');
        $this->assertNull($consult->reminder_24h_sent_at, 'وأختام التذكير تُعاد تسليحها');
    }

    /**
     * **اللوازم الأربعة مجتمعة** — الحارس الذي يمنع «نصف الإصلاح».
     *
     * أيّ لازمٍ يُنسى مستقبلاً يُسقط هذا الاختبار، لأن المسارات كلّها تمرّ بـ
     * `BookingMoved` الواحدة.
     */
    public function test_moving_a_consult_rearms_every_coupled_stamp(): void
    {
        $this->fakeZoom();
        [$client] = $this->cast();

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-MV-'.uniqid(), 'subject' => 'نزاع',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'جديدة',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-blue', 'lawyer' => 'مستشار',
            'starts_at' => now()->addDays(2), 'meet_id' => '9444', 'duration_min' => 60,
            'link_released_at' => now(), 'reminder_24h_sent_at' => now(), 'reminder_30m_sent_at' => now(),
        ]);

        $newStart = now()->addDays(6)->setTime(14, 0);
        BookingMoved::apply($consult, $newStart);

        $consult->refresh();
        $this->assertNull($consult->link_released_at);
        $this->assertNull($consult->reminder_24h_sent_at);
        $this->assertNull($consult->reminder_30m_sent_at);

        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/meetings/9444')
            && ($r->data()['duration'] ?? null) === 60);
    }

    /** وتعثّر Zoom لا يُسقط تحريك الموعد — لكنّه لا يُبتلع صامتاً. */
    public function test_a_zoom_failure_does_not_block_the_move(): void
    {
        config([
            'services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b',
            'services.zoom.client_secret' => 'c',
        ]);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'api.zoom.us/v2/*' => Http::response([], 500),
        ]);

        [$client] = $this->cast();
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-FAIL-'.uniqid(), 'subject' => 'نزاع',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'جديدة',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-blue', 'lawyer' => 'مستشار',
            'starts_at' => now()->addDays(2), 'meet_id' => '9555', 'link_released_at' => now(),
        ]);

        BookingMoved::apply($consult, now()->addDays(5));

        // الأختام أُعيد تسليحها رغم تعثّر المزوّد
        $this->assertNull($consult->fresh()->link_released_at);
    }
}
