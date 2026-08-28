<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CaseConversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * لا قضية بلا محامٍ + الحارس الموحّد.
 *
 * كان CaseConversion::createCase ينسخ assigned_lawyer_id كما هو، وTicketAssignment::assign
 * يُعيد null إن لم يوجد محامٍ نشط في وضع التوزيع التلقائي. ومسار الموظف لا يفحص الإسناد،
 * ومسار المحامي يفحصه بـguardAssigned لكنّه **يعفي الإدارة** — فتُنشأ قضية بلا محامٍ تختفي
 * من قائمة كل محامٍ ومن عدّادات لوحته: قضيّة يتيمة لا يعمل عليها أحد.
 */
class CaseLawyerResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function unassignedTicket(User $client): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-7310',
            'type' => 'نزاع تجاري',
            'department' => 'القضايا التجارية',
            'assigned_lawyer' => null,
            'assigned_lawyer_id' => null,
            'status' => 'مكتملة',
            'tone' => 'b-green',
        ]);
    }

    /** تذكرة بلا إسناد + محامٍ نشط في التوزيع التلقائي ⇒ يُختار حتمياً وتُكتب النتيجة على الاثنين. */
    public function test_conversion_assigns_a_lawyer_when_the_ticket_has_none(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create([
            'role' => Role::Lawyer, 'status' => 'active',
            'distribution_mode' => 'auto', 'department' => 'القضايا التجارية',
        ]);
        $ticket = $this->unassignedTicket($client);

        $case = CaseConversion::convert($ticket, $admin);

        $this->assertSame($lawyer->id, $case->assigned_lawyer_id, 'القضية أُنشئت بلا محامٍ.');
        $this->assertSame($lawyer->name, $case->assigned_lawyer);
        // والتذكرة تُحدَّث أيضاً فلا يتباعد سجلّها عن قضيّتها
        $this->assertSame($lawyer->id, $ticket->fresh()->assigned_lawyer_id);
    }

    /** لا محامي توزيع تلقائي والمحوِّل محامٍ ⇒ يُسنِد نفسه (نظير ExecutionCreation). */
    public function test_conversion_falls_back_to_the_converting_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        // manual يُخرجه من مرشّحي pickLawyer فتُبلَغ الخطوة الثالثة
        $lawyer = User::factory()->create([
            'role' => Role::Lawyer, 'status' => 'active', 'distribution_mode' => 'manual',
        ]);
        $ticket = $this->unassignedTicket($client);

        $case = CaseConversion::convert($ticket, $lawyer);

        $this->assertSame($lawyer->id, $case->assigned_lawyer_id);
    }

    /** لا محامٍ البتّة والمحوِّل إدارة ⇒ تُرفض بالعربية ولا تُنشأ قضية يتيمة. */
    public function test_conversion_is_refused_when_no_lawyer_can_be_resolved(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->unassignedTicket($client);

        try {
            CaseConversion::convert($ticket, $admin);
            $this->fail('كان يجب رفض التحويل بلا محامٍ.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'لا يمكن تحويل التذكرة لقضية بلا محامٍ مسند — أسند التذكرة لمحامٍ أولاً.',
                $e->errors()['ticket'][0]
            );
        }

        $this->assertSame(0, LegalCase::where('ticket_id', $ticket->id)->count());
    }

    /** الحارس الموحّد يحفظ رسائل المتحكّمَين حرفياً — شبكة أمان لتوحيد assertEligible. */
    public function test_the_unified_guard_keeps_the_exact_messages(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->unassignedTicket($client);
        $ticket->update(['status' => 'الرأي القانوني']);

        foreach ([true, false] as $requireSummary) {
            try {
                CaseConversion::assertEligible($ticket, $requireSummary);
                $this->fail('كان يجب رفض تذكرة غير مكتملة.');
            } catch (ValidationException $e) {
                $this->assertSame('لا يمكن تحويل التذكرة لقضية إلا بعد اكتمالها.', $e->errors()['ticket'][0]);
            }
        }

        // مكتملة بلا اعتماد: تُرفض لمسار الموظف وتمرّ لمسار المحامي/الإدارة
        $ticket->update(['status' => 'مكتملة']);
        try {
            CaseConversion::assertEligible($ticket, true);
            $this->fail('كان يجب رفضها بلا اعتماد النتيجة.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'لا يمكن تحويل التذكرة لقضية إلا بعد اعتماد النتيجة من المستشار القانوني.',
                $e->errors()['ticket'][0]
            );
        }
        CaseConversion::assertEligible($ticket, false); // لا يرمي
        $this->assertTrue(true);
    }
}
