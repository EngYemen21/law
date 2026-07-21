<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ConsultBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اتساق إسناد المحامي (assigned_lawyer_id مصدر الحقيقة): كل مسارات إنشاء/تغيير المحامي تربط الـFK
 * والفرع — لا الاسم وحده — كي لا تنكسر عزلة الرؤية ولا تُخطئ توجيه المهام.
 */
class AssignmentConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_derives_lawyer_and_branch_from_ticket_when_no_lawyer_id(): void
    {
        // يختبر الاشتقاق في ConsultBooking مباشرةً: أي مسار يمرّر تذكرة دون lawyer_id
        // يجب أن يرث المحامي المسند وفرعه من التذكرة (لا اسماً فقط).
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-900', 'type' => 'نزاع', 'status' => 'قيد المعالجة',
            'assigned_lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id, 'branch' => 'فرع الرياض',
        ]);

        $consult = ConsultBooking::create($client, ['type' => 'phone', 'day' => '2026-07-20', 'time' => '11:00'], $ticket);

        $this->assertSame($lawyer->id, $consult->assigned_lawyer_id); // مشتقّ من التذكرة رغم غياب lawyer_id
        $this->assertSame('فرع الرياض', $consult->branch);
    }

    public function test_refer_binds_assigned_lawyer_id(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الرياض']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-8100', 'subject' => 'نزاع', 'channel' => 'هاتفية',
            'lawyer' => 'المستشار القانوني', 'branch' => 'فرع الرياض',
            'day' => 'الأحد', 'time' => '10ص', 'when_label' => 'الأحد', 'status' => 'جاهزة للمحامي',
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
        $employee = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الرياض']);
        $lawyerA = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $lawyerB = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-901', 'type' => 'نزاع', 'status' => 'قيد المعالجة',
            'assigned_lawyer' => $lawyerA->name, 'assigned_lawyer_id' => $lawyerA->id, 'branch' => 'فرع الرياض',
        ]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ticket_id' => $ticket->id, 'ref' => 'CN-2026-8200', 'subject' => 'نزاع',
            'channel' => 'هاتفية', 'lawyer' => $lawyerA->name, 'assigned_lawyer_id' => $lawyerA->id,
            'branch' => 'فرع الرياض', 'day' => 'الأحد', 'time' => '10ص', 'when_label' => 'الأحد',
            'session' => 'بانتظار الجلسة',
        ]);

        $this->actingAs($employee)->post(route('employee.transfer.do', $ticket), [
            'lawyer_id' => $lawyerB->id,
        ])->assertRedirect();

        // الاستشارة المفتوحة انتقلت للمحامي الجديد
        $this->assertSame($lawyerB->id, $consult->fresh()->assigned_lawyer_id);
    }
}
