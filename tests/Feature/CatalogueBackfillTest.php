<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CatalogueBackfill;
use App\Support\LegalCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ملء روابط الكتالوج للسجلّات القديمة: يملأ الفارغ بمطابقةٍ صارمة، ويترك ما لا يُطابَق،
 * ولا يغيّر شيئاً في تشغيله الثاني، ولا يكتب في المعاينة.
 */
class CatalogueBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** صفّ تذكرة «قديم»: يُنشأ ثمّ تُمحى روابطه كأنه سبق الكتالوج. */
    private function legacyTicket(?string $department, string $type): Ticket
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-OLD-'.uniqid(), 'type' => $type, 'subject' => 'قديم',
            'department' => $department, 'status' => 'قيد التحليل', 'tone' => 'b-blue', 'priority' => 'متوسطة',
        ]);
        DB::table('tickets')->where('id', $ticket->id)->update(['legal_department_id' => null, 'legal_service_id' => null]);

        return $ticket;
    }

    private function legacyLawyer(string $department): User
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'department' => $department]);
        DB::table('users')->where('id', $lawyer->id)->update(['covers_all_departments' => false]);

        return $lawyer;
    }

    public function test_links_legacy_rows_and_reports_what_did_not_match(): void
    {
        $byAlias = $this->legacyTicket('القسم التجاري', 'المطالبات المالية التجارية');
        $byType = $this->legacyTicket(null, 'نزاع عقاري');
        $unknown = $this->legacyTicket('قسمٌ مجهول', 'نوعٌ مجهول');
        $case = LegalCase::create([
            'user_id' => $byAlias->user_id, 'ticket_id' => $byAlias->id, 'number' => 'CASE-OLD-1', 'type' => 'نزاع تجاري',
            'department' => 'البنوك والتمويل', 'status' => 'بانتظار اعتماد الأتعاب', 'tone' => 'b-amber', 'fee_status' => 'none',
        ]);
        DB::table('cases')->where('id', $case->id)->update(['legal_department_id' => null]);

        $general = $this->legacyLawyer('كل الأقسام');
        $commercial = $this->legacyLawyer('القسم التجاري');
        $civil = $this->legacyLawyer('مدني');

        $report = CatalogueBackfill::run();

        $this->assertSame(LegalCatalogue::department('commercial')->id, $byAlias->fresh()->legal_department_id);
        $this->assertSame('المطالبات المالية التجارية', $byAlias->fresh()->legalService?->name);
        $this->assertSame(LegalCatalogue::department('real_estate')->id, $byType->fresh()->legal_department_id, 'النوع يدلّ على القسم حين يغيب.');
        $this->assertNull($unknown->fresh()->legal_department_id);
        $this->assertSame(['قسمٌ مجهول' => 1], $report['التذاكر']['unresolved']);
        $this->assertSame(LegalCatalogue::department('banking')->id, $case->fresh()->legal_department_id);

        $this->assertTrue((bool) $general->fresh()->covers_all_departments);
        $this->assertSame([LegalCatalogue::department('commercial')->id], $commercial->fresh()->specialties->pluck('id')->all());
        $this->assertSame(['مدني' => 1], $report['المحامون']['unresolved']);
        $this->assertCount(0, $civil->fresh()->specialties);

        $again = CatalogueBackfill::run();
        foreach ($again as $group => $result) {
            $this->assertSame(0, $result['linked'], "التشغيل الثاني ربط شيئاً في «{$group}».");
        }
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $ticket = $this->legacyTicket('القسم التجاري', 'نزاع تجاري');
        $lawyer = $this->legacyLawyer('القسم التجاري');

        $report = CatalogueBackfill::run(dryRun: true);

        $this->assertSame(1, $report['التذاكر']['linked']);
        $this->assertNull($ticket->fresh()->legal_department_id);
        $this->assertCount(0, $lawyer->fresh()->specialties);
    }

    public function test_command_runs_in_dry_run_mode(): void
    {
        $this->legacyTicket('قسمٌ مجهول', 'نوعٌ مجهول');

        $this->artisan('catalogue:backfill', ['--dry-run' => true])
            ->expectsOutputToContain('معاينة')
            ->assertSuccessful();
    }
}
