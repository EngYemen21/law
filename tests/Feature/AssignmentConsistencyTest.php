<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ConsultAppointments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * اتساق إسناد المحامي (assigned_lawyer_id مصدر الحقيقة): كل مسارات إنشاء/تغيير المحامي تربط الـFK
 * بالمعرّف — لا الاسم وحده — كي لا تُخطئ عزلة المحامي ولا توجيه المهام.
 */
class AssignmentConsistencyTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    public function test_booking_derives_lawyer_from_ticket_when_no_lawyer_id(): void
    {
        // حجز الطاقم دون اختيار محامٍ يرث محامي التذكرة المسند (ع٢٧) — لا أوّلَ متفرّغٍ غريب.
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']); // متفرّغٌ آخر لا يُختار
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-900', 'type' => 'نزاع', 'status' => 'بانتظار تحديد الموعد',
            'assigned_lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id, ]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ticket_id' => $ticket->id, 'ref' => 'CN-AC-900', 'subject' => 'نزاع',
            'type' => 'استشارة', 'channel' => 'هاتفية', 'status' => 'بانتظار تحديد الموعد',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-amber', 'lawyer' => 'المستشار القانوني',
            'priced_at' => now(), 'paid_at' => now(),
        ]);

        $consult = ConsultAppointments::publish($consult, $this->journeyAdmin(), [
            'type' => 'phone', 'date' => now()->addDays(2)->toDateString(), 'time' => '11:00',
        ]);

        $this->assertSame($lawyer->id, $consult->assigned_lawyer_id); // مشتقّ من التذكرة رغم غياب lawyer_id
        $this->assertSame($lawyer->name, $consult->lawyer);
    }

    public function test_refer_binds_assigned_lawyer_id(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-8100', 'subject' => 'نزاع', 'channel' => 'هاتفية',
            'lawyer' => 'المستشار القانوني', 'day' => 'الأحد', 'time' => '10ص', 'when_label' => 'الأحد', 'status' => 'جاهزة للمحامي',
        ]);

        $this->actingAs($employee)->post(route('employee.consults.refer', $consult), [
            'lawyer_id' => $lawyer->id,
        ])->assertRedirect();

        $consult->refresh();
        $this->assertSame($lawyer->id, $consult->assigned_lawyer_id);
        $this->assertSame('محالة للمحامي', $consult->status);
    }

    public function test_ticket_transfer_propagates_to_open_consults(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $lawyerA = User::factory()->create(['role' => Role::Lawyer]);
        $lawyerB = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-901', 'type' => 'نزاع', 'status' => 'قيد المعالجة',
            'assigned_lawyer' => $lawyerA->name, 'assigned_lawyer_id' => $lawyerA->id, ]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ticket_id' => $ticket->id, 'ref' => 'CN-2026-8200', 'subject' => 'نزاع',
            'channel' => 'هاتفية', 'lawyer' => $lawyerA->name, 'assigned_lawyer_id' => $lawyerA->id,
            'day' => 'الأحد', 'time' => '10ص', 'when_label' => 'الأحد',
            'session' => 'بانتظار الجلسة',
        ]);

        $this->actingAs($employee)->post(route('employee.transfer.do', $ticket), [
            'lawyer_id' => $lawyerB->id,
        ])->assertRedirect();

        // الاستشارة المفتوحة انتقلت للمحامي الجديد
        $this->assertSame($lawyerB->id, $consult->fresh()->assigned_lawyer_id);
    }
}
