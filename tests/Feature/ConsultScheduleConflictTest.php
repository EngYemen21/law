<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ConsultBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * تعارض جدولة الاستشارة المرئيّة: يجب حذف اجتماع Zoom المُنشأ (منع اليتيم) عند فشل المعاملة.
 */
class ConsultScheduleConflictTest extends TestCase
{
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
        $ticket = Ticket::create(['user_id' => $client->id, 'number' => 'SB-2026-9099', 'type' => 'نزاع تجاري', 'department' => 'القسم التجاري', 'status' => 'بانتظار حجز الاستشارة', 'tone' => 'b-amber']);
        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'video'])->assertNoContent();
        $consult = $ticket->consults()->latest('id')->firstOrFail();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => 450])->assertRedirect();
        ConsultBooking::markPaid($consult->fresh());
        $consult = $consult->fresh();

        // موعد مؤكّد للمحامي نفسه في نفس الوقت → تعارض
        $startsAt = now()->addDay()->setTime(11, 30);
        Appointment::create(['user_id' => $client->id, 'ext_id' => 'AP-CONF', 'type' => 'استشارة', 'ico' => 'video', 'lawyer' => $lawyer->name, 'lawyer_id' => $lawyer->id, 'day' => 'غد', 'time' => '11:30', 'starts_at' => $startsAt, 'duration_min' => 30, 'place' => 'الرياض', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up']);

        $slot = ['lawyer_id' => $lawyer->id, 'day' => 'غد', 'time' => '11:30', 'starts_at' => $startsAt->toDateTimeString(), 'duration' => 30];

        try {
            ConsultBooking::schedule($consult, $slot);
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
