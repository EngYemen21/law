<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\User;
use App\Support\ConsultAppointments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * تعارض موعد الاستشارة المرئيّة: يجب حذف اجتماع Zoom المُنشأ (منع اليتيم) عند رفض النشر.
 */
class ConsultScheduleConflictTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    public function test_scheduling_conflict_deletes_orphaned_zoom_meeting(): void
    {
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/*' => Http::response(['id' => 987654321, 'join_url' => 'https://z/j', 'start_url' => 'https://z/s', 'password' => 'pw']),
        ]);

        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'أ. سارة القحطاني']);

        // استشارة مرئيّة وصلت إلى «بانتظار تحديد الموعد» عبر الدورة
        $ticket = $this->ticketWithApprovedOpinion($client, [
            'number' => 'SB-2026-9099', 'department' => 'القسم التجاري', 'status' => 'بانتظار حجز الاستشارة',
        ]);
        $consult = $this->requestPricedAndPaid($client, $ticket, 'video');

        // موعد مؤكّد للمحامي نفسه في نفس الوقت → تعارض
        $startsAt = now()->addDay()->setTime(11, 30);
        Appointment::create(['user_id' => $client->id, 'ext_id' => 'AP-CONF', 'type' => 'استشارة', 'ico' => 'video', 'lawyer' => $lawyer->name, 'lawyer_id' => $lawyer->id, 'day' => 'غد', 'time' => '11:30', 'starts_at' => $startsAt, 'duration_min' => 30, 'place' => 'الرياض', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up']);

        try {
            ConsultAppointments::publish($consult, $this->journeyAdmin(), [
                'lawyer_id' => $lawyer->id, 'date' => $startsAt->toDateString(), 'time' => '11:30',
            ]);
            $this->fail('توقّعنا استثناء تعارض الموعد.');
        } catch (ValidationException) {
            // متوقّع — حارس التعارض
        }

        // اجتماع Zoom الذي أُنشئ قبل الحارس يُحذف (منع اليتيم)
        Http::assertSent(fn ($req) => $req->method() === 'DELETE' && str_contains($req->url(), 'meetings/987654321'));

        // ولم تُجدوَل الاستشارة (بقيت بانتظار تحديد الموعد بلا موعد)
        $consult->refresh();
        $this->assertSame('بانتظار تحديد الموعد', $consult->status);
        $this->assertNull($consult->appointment_id);
    }
}
