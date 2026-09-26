<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * تقرير الاستشارة PDF — يُصيَّر فعلياً عبر Browsershot (كروم مخفي حقيقي)، لا صورة/محاكاة.
 */
class ConsultReportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_downloads_real_pdf_report(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id,
            'ref' => 'CN-2026-9001',
            'subject' => 'نزاع تجاري',
            'specialty' => 'القضايا التجارية',
            'status' => 'جديدة',
            'session' => 'بانتظار الجلسة',
            'channel' => 'مرئية',
            'lawyer' => 'أ. سارة القحطاني',
            'when_label' => 'الاثنين 10 أغسطس · 11:00',
            'price' => 450,
            'vat' => 68,
            'total' => 518,
            'priced_at' => now(),
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($client)->get(route('consults.report', $consult));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('CN-2026-9001.pdf', (string) $response->headers->get('Content-Disposition'));
        // ملف PDF حقيقي (يبدأ بالتوقيع القياسي) — تأكيد أنه تصيير فعلي لا نص/صورة وهمية
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_other_client_cannot_download_foreign_report(): void
    {
        $owner = User::factory()->create(['role' => Role::Client]);
        $intruder = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $owner->id,
            'ref' => 'CN-2026-9002',
            'subject' => 'استشارة',
            'status' => 'جديدة',
            'session' => 'بانتظار الجلسة',
            'channel' => 'هاتفية',
            'lawyer' => 'أ. سارة القحطاني',
        ]);

        $this->assertPageRefused($this->actingAs($intruder)->get(route('consults.report', $consult)));
    }

    /**
     * 🔴 كان الحارس `|| isEmployee() || isLawyer()` يُلغي شرطَي الملكية والإسناد قبله،
     * فأي موظف أو محامٍ — بلا صلاحية وبلا إسناد — يسحب تقرير أي عميل (الموضوع، حالة السداد، الملخّص).
     * وشرط الإسناد نفسه كان ميتاً: `$consult->lawyer_id` لا وجود لعموده على جدول consults.
     */
    public function test_unassigned_lawyer_cannot_read_another_lawyers_consult_report(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $owner = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->makeConsult($client, $owner);

        // محامٍ زميل يملك كل الصلاحيات — العزل بالإسناد لا بالصلاحية
        $outsider = User::factory()->create(['role' => Role::Lawyer]);

        $this->assertPageRefused($this->actingAs($outsider)
            ->get(route('consults.report', $consult)));
    }

    /** الموظف بلا صلاحية «استقبال الاستشارات» يُمنع (الموظف بصلاحيته يرى سجلات المكتب عمداً). */
    public function test_employee_without_permission_cannot_read_the_report(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $owner = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->makeConsult($client, $owner);

        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions([]);

        $this->assertPageRefused($this->actingAs($employee)
            ->get(route('consults.report', $consult)));
    }

    /** المحامي المسنَد يبقى قادراً — الإصلاح يجب ألّا يحجب صاحب العمل. */
    public function test_assigned_lawyer_still_reads_the_report(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $owner = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->makeConsult($client, $owner);

        $this->actingAs($owner)->get(route('consults.report.plain', $consult))->assertOk();
    }

    /** الموظف صاحب الصلاحية يبقى قادراً (تشغيل المكتب). */
    public function test_permitted_employee_reads_the_report(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $owner = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->makeConsult($client, $owner);

        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions([Permission::findOrCreate('استقبال الاستشارات', 'web')]);

        $this->actingAs($employee)->get(route('consults.report.plain', $consult))->assertOk();
    }

    private function makeConsult(User $client, User $lawyer): Consult
    {
        return Consult::create([
            'user_id' => $client->id,
            'assigned_lawyer_id' => $lawyer->id,
            'ref' => 'CN-2026-9'.random_int(100, 999),
            'subject' => 'استشارة',
            'specialty' => 'القضايا التجارية',
            'status' => 'جديدة',
            'session' => 'بانتظار الجلسة',
            'channel' => 'هاتفية',
            'lawyer' => $lawyer->name,
        ]);
    }
}
