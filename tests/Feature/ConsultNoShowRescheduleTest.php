<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * دورة «لم يحضر/إعادة الجدولة» للاستشارة الفائتة (الدفعة 2):
 * كانت الفائتة تعلق «بانتظار الجلسة» للأبد، والحيلة الوحيدة (بدء+إنهاء فوري) تزوّر السجل جلسةً منعقدة.
 */
class ConsultNoShowRescheduleTest extends TestCase
{
    use RefreshDatabase;

    private function consult(User $client, array $extra = []): Consult
    {
        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-2026-'.random_int(1000, 9999),
            'subject' => 'نزاع تجاري',
            'channel' => 'مرئية',
            'lawyer' => 'أ. سارة',
            'day' => 'أمس', 'time' => '10ص', 'when_label' => 'أمس · 10ص',
            'session' => 'بانتظار الجلسة', 'status' => 'موعد مؤكد',
            'starts_at' => now()->subHours(3), 'duration_min' => 45,
        ], $extra));
    }

    public function test_staff_marks_missed_consult_no_show(): void
    {
        Event::fake([ConsultStatusBroadcast::class]);
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $consult = $this->consult($client);

        $this->assertTrue($consult->isMissed());
        $this->actingAs($employee)->post(route('employee.consults.noshow', $consult))->assertRedirect();

        $consult->refresh();
        $this->assertSame('لم تُعقد', $consult->session);
        $this->assertSame('لم يحضر', $consult->status);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
        Event::assertDispatched(ConsultStatusBroadcast::class);
        // القيد موثّق في سجل التدقيق
        $this->assertSame('الجلسة', $consult->audit[0]['field']);
    }

    public function test_no_show_rejected_before_appointment_time_or_after_session(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        // موعد مستقبلي — لم يفت بعد
        $future = $this->consult($client, ['starts_at' => now()->addDay()]);
        $this->actingAs($employee)->post(route('employee.consults.noshow', $future))->assertStatus(422);

        // جلسة جارية — لا يصحّ وسمها «لم يحضر»
        $live = $this->consult($client, ['session' => 'جلسة جارية', 'status' => 'قيد الاستشارة']);
        $this->actingAs($employee)->post(route('employee.consults.noshow', $live))->assertStatus(422);
    }

    public function test_reschedule_resets_booking_and_cancels_old_appointment(): void
    {
        Event::fake([ConsultStatusBroadcast::class]);
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $appt = Appointment::create([
            'user_id' => $client->id, 'ext_id' => 'AP-26-7001', 'type' => 'استشارة مرئية', 'ico' => 'video',
            'lawyer' => 'أ. سارة', 'day' => 'أمس', 'time' => '10ص',
            'starts_at' => now()->subHours(3), 'duration_min' => 45,
            'place' => 'اجتماع إلكتروني', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up',
        ]);
        $consult = $this->consult($client, [
            'appointment_id' => $appt->id,
            'session' => 'لم تُعقد', 'status' => 'لم يحضر',
            'meet_id' => '123', 'meet_link' => 'https://z/j', 'host_link' => 'https://z/s',
            'meet_password' => 'pw', 'link_released_at' => now()->subHours(4),
        ]);

        $this->actingAs($employee)->post(route('employee.consults.reschedule', $consult), ['reason' => 'client_absent'])->assertRedirect();

        $consult->refresh();
        // تعود لمرحلة اختيار الموعد ضمن دورة الحجز المدفوعة
        $this->assertSame('بانتظار تحديد الموعد', $consult->status);
        $this->assertSame('بانتظار الجلسة', $consult->session);
        $this->assertNull($consult->starts_at);
        // بيانات Zoom القديمة صُفّرت — لم تعد صالحة للموعد الجديد
        $this->assertNull($consult->meet_id);
        $this->assertNull($consult->meet_link);
        $this->assertNull($consult->link_released_at);
        // الموعد القديم «ملغي» لا «لم يحضر» (أُعيدت جدولته)
        $this->assertSame('ملغي', $appt->fresh()->status);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
    }

    public function test_reschedule_rejected_for_ended_or_pre_session_consult(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        $ended = $this->consult($client, ['session' => 'منتهية', 'status' => 'منتهية']);
        $this->actingAs($employee)->post(route('employee.consults.reschedule', $ended), ['reason' => 'client_request'])->assertStatus(422);

        $preSession = $this->consult($client, ['status' => 'بانتظار التسعير']);
        $this->actingAs($employee)->post(route('employee.consults.reschedule', $preSession), ['reason' => 'client_request'])->assertStatus(422);
    }

    public function test_lawyer_and_admin_routes_share_the_cycle_with_guards(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyerA = User::factory()->create(['role' => Role::Lawyer]);
        $lawyerB = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        // المحامي غير المسنَد ممنوع (guardAssigned)
        $foreign = $this->consult($client, ['assigned_lawyer_id' => $lawyerA->id]);
        $this->actingAs($lawyerB)->post(route('lawyer.consults.noshow', $foreign))->assertForbidden();

        // المحامي المسنَد يسم «لم يحضر»، والإدارة تعيد الجدولة
        $this->actingAs($lawyerA)->post(route('lawyer.consults.noshow', $foreign))->assertRedirect();
        $this->assertSame('لم يحضر', $foreign->fresh()->status);
        $this->actingAs($admin)->post(route('admin.consults.reschedule', $foreign), ['reason' => 'client_request'])->assertRedirect();
        $this->assertSame('بانتظار تحديد الموعد', $foreign->fresh()->status);
    }
}
