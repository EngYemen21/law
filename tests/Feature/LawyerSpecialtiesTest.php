<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LawyerSpecialties;
use App\Support\LegalCatalogue;
use App\Support\TicketAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مطابقة المحامي بالقسم عبر الكتالوج: تخصّصاتٌ متعدّدة، وتغطيةٌ عامّة، ونصوصٌ قديمة،
 * وربط التذكرة بقسمها وخدمتها عند الحفظ.
 */
class LawyerSpecialtiesTest extends TestCase
{
    use RefreshDatabase;

    private function lawyer(array $attributes = [], array $departmentCodes = []): User
    {
        $lawyer = User::factory()->create($attributes + [
            'role' => Role::Lawyer, 'status' => 'active', 'distribution_mode' => 'auto', 'department' => null,
        ]);

        $lawyer->specialties()->sync(array_map(fn (string $code) => LegalCatalogue::department($code)->id, $departmentCodes));

        return $lawyer->fresh();
    }

    private function deptId(string $code): int
    {
        return LegalCatalogue::department($code)->id;
    }

    private function ticket(array $attributes = []): Ticket
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Ticket::create($attributes + [
            'user_id' => $client->id, 'number' => 'SB-SPEC-'.uniqid(), 'type' => 'استشارة', 'subject' => 'اختبار',
            'status' => 'قيد التحليل', 'tone' => 'b-blue', 'priority' => 'متوسطة',
        ]);
    }

    public function test_a_lawyer_with_several_specialties_covers_each_of_them_only(): void
    {
        $lawyer = $this->lawyer([], ['labor', 'insurance']);

        $this->assertTrue(LawyerSpecialties::covers($lawyer, $this->deptId('labor')));
        $this->assertTrue(LawyerSpecialties::covers($lawyer, $this->deptId('insurance')));
        $this->assertFalse(LawyerSpecialties::covers($lawyer, $this->deptId('real_estate')));
    }

    public function test_covering_all_departments_by_flag_or_legacy_text(): void
    {
        $this->assertTrue(LawyerSpecialties::covers($this->lawyer(['covers_all_departments' => true]), $this->deptId('medical')));
        $this->assertTrue(LawyerSpecialties::covers($this->lawyer(['department' => 'كل الأقسام']), $this->deptId('medical')));
    }

    public function test_legacy_department_text_still_matches_when_no_specialties_are_linked(): void
    {
        $lawyer = $this->lawyer(['department' => 'القسم التجاري']);

        $this->assertTrue(LawyerSpecialties::covers($lawyer, null, 'القضايا التجارية'));
        $this->assertFalse(LawyerSpecialties::covers($lawyer, null, 'القضايا العمالية'));
    }

    public function test_linked_specialties_take_precedence_over_the_legacy_text(): void
    {
        $lawyer = $this->lawyer(['department' => 'القضايا العمالية'], ['insurance']);

        $this->assertFalse(LawyerSpecialties::covers($lawyer, $this->deptId('labor')));
        $this->assertTrue(LawyerSpecialties::covers($lawyer, $this->deptId('insurance')));
    }

    public function test_unknown_free_text_falls_back_to_exact_text_equality(): void
    {
        $lawyer = $this->lawyer(['department' => 'مدني']);

        $this->assertTrue(LawyerSpecialties::covers($lawyer, null, 'مدني'));
        $this->assertFalse(LawyerSpecialties::covers($lawyer, null, 'جنائي دولي'));
    }

    /** 🔴 «الأخطاء الطبية» كانت لا تطابق أيّ تخصّص فتُصعَّد كلّ تذاكرها للإدارة. */
    public function test_a_formerly_unmatched_department_is_now_auto_assigned_to_its_specialist(): void
    {
        $this->lawyer([], ['labor']);
        $specialist = $this->lawyer([], ['medical']);
        $ticket = $this->ticket(['department' => 'الأخطاء الطبية']);

        $this->assertSame($specialist->id, TicketAssignment::pickLawyer($ticket, requireSpecialty: true)?->id);
    }

    public function test_no_specialist_still_escalates_instead_of_guessing(): void
    {
        $this->lawyer([], ['labor']);
        $ticket = $this->ticket(['department' => 'الأخطاء الطبية']);

        $this->assertNull(TicketAssignment::pickLawyer($ticket, requireSpecialty: true));
    }

    public function test_saving_a_ticket_links_its_department_and_service(): void
    {
        $ticket = $this->ticket(['department' => 'القسم التجاري', 'type' => 'المطالبات المالية التجارية']);

        $this->assertSame($this->deptId('commercial'), $ticket->legal_department_id);
        $this->assertSame('المطالبات المالية التجارية', $ticket->legalService?->name);

        $ticket->update(['department' => 'قسم لا يعرفه الكتالوج']);
        $this->assertNull($ticket->fresh()->legal_department_id, 'نصٌّ لا يُطابَق لا يُخمَّن له قسم.');
        $this->assertNull($ticket->fresh()->legal_service_id);
    }

    public function test_an_explicit_department_id_is_not_overwritten_by_the_text(): void
    {
        $ticket = $this->ticket(['department' => 'نصٌّ حرّ', 'legal_department_id' => $this->deptId('labor')]);

        $this->assertSame($this->deptId('labor'), $ticket->fresh()->legal_department_id);
    }
}
