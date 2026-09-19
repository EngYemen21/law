<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\CaseJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **رفع الدعوى في ناجز ثمّ قيدها.** (قرار المالك 2026-09-11 — الخطّة ب)
 *
 * كان زرّ «الاعتماد النهائيّ ورفع الدعوى» يجمع ثلاث خطواتٍ في ضغطة: يجعل القضيّة «منظورة»
 * ويبلّغ العميل «رُفعت الدعوى» قبل أن تُرفع في ناجز، ولا مكان لرقم الطلب ولا رقم القضيّة ولا
 * الدائرة، والجلسة الأولى تُضاف يدوياً كأيّ جلسة.
 */
class NajizFilingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: LegalCase, 1: User, 2: User} */
    private function approvedCase(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $case = LegalCase::create([
            'user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id, 'number' => 'CASE-NJ-'.uniqid(),
            'type' => 'نزاع تجاري', 'status' => 'قيد التحضير', 'tone' => 'b-blue', 'pleading_status' => 'approved',
        ]);

        return [$case, $lawyer, $client];
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'case_no' => '4700123456', 'court' => 'المحكمة التجارية بالرياض', 'circuit' => 'الدائرة التجارية الأولى',
            'registered_at' => now()->toDateString(), 'hearing_day' => now()->addDays(10)->toDateString(),
            'hearing_time' => '09:00', 'hearing_mode' => 'عن بُعد',
        ], $overrides);
    }

    private function lastNotice(User $user): string
    {
        return (string) DB::table('user_notifications')->where('user_id', $user->id)->orderByDesc('id')->value('body');
    }

    public function test_the_catalogue_knows_awaiting_registration(): void
    {
        $this->assertArrayHasKey('بانتظار القيد', CaseJourney::STATUSES);
        $this->assertSame(2, CaseJourney::stage('بانتظار القيد'), 'مرحلة «رفع الدعوى»');
        $this->assertContains('بانتظار القيد', CaseJourney::ACTIVE);
        $this->assertContains('بانتظار القيد', CaseJourney::clientTabs()['active']);
        $this->assertStringContainsString("case 'بانتظار القيد':", (string) file_get_contents(resource_path('js/lib/case-ui.tsx')));
    }

    public function test_filing_moves_the_case_to_awaiting_registration_and_tells_the_client(): void
    {
        [$case, $lawyer, $client] = $this->approvedCase();

        $this->actingAs($lawyer)->post(route('lawyer.cases.najiz.file', $case), ['request_no' => 'NJ-777', 'filed_at' => now()->toDateString()])
            ->assertRedirect();

        $case->refresh();
        $this->assertSame('بانتظار القيد', $case->status);
        $this->assertSame('NJ-777', $case->najiz_request_no);
        $this->assertStringContainsString('NJ-777', $this->lastNotice($client));
        $this->assertStringContainsString('بانتظار قيد المحكمة', $this->lastNotice($client));

        // لا جلسات قبل القيد، والحكم كذلك
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.add', $case), ['title' => 'x', 'day' => now()->addWeek()->toDateString(), 'time' => '10:00'])->assertStatus(422);
        $this->actingAs($lawyer)->post(route('lawyer.cases.ruling', $case), ['ruling' => 'x'])->assertStatus(422);

        // والشاشة تعرض نموذج القيد لا نموذج الرفع
        $this->actingAs($lawyer)->get(route('lawyer.cases.show', $case))->assertInertia(fn ($p) => $p
            ->where('filing.canFile', false)->where('filing.canRegister', true)->where('filing.data.requestNo', 'NJ-777'));
    }

    public function test_registration_files_the_case_and_schedules_the_first_hearing(): void
    {
        [$case, $lawyer, $client] = $this->approvedCase();
        $this->actingAs($lawyer)->post(route('lawyer.cases.najiz.file', $case), ['request_no' => 'NJ-778', 'filed_at' => now()->toDateString()]);

        $this->actingAs($lawyer)->post(route('lawyer.cases.najiz.register', $case), $this->registration([
            'file' => UploadedFile::fake()->create('صحيفة.pdf', 40, 'application/pdf'),
        ]))->assertRedirect();

        $case->refresh();
        $this->assertSame('منظورة', $case->status);
        $this->assertSame('4700123456', $case->najiz_case_no);
        $this->assertSame('الدائرة التجارية الأولى', $case->circuit);

        // الجلسة الأولى عبر مسار الجدولة نفسه — بموعدٍ حقيقيّ تعمل عليه التذكيرات
        $hearing = $case->hearings()->firstOrFail();
        $this->assertSame('الجلسة الأولى — عن بُعد', $hearing->title);
        $this->assertSame('09:00', $hearing->starts_at?->format('H:i'));
        $this->assertSame('الدائرة التجارية الأولى', $hearing->court);

        // وصورة الصحيفة المقيّدة ضمن مستندات القضيّة
        $this->assertSame('صحيفة الدعوى المقيّدة', $case->documents()->value('doc_type'));

        // والعميل يرى رقم القضيّة
        // إشعار القيد برقم القضيّة (ويليه إشعار الجلسة الأولى — فلا يُفترض أنه الأخير)
        $this->assertTrue(
            DB::table('user_notifications')->where('user_id', $client->id)->where('body', 'like', '%قُيّدت%4700123456%')->exists(),
            'العميل يُبلَّغ بقيد دعواه ورقمها'
        );
        $this->assertTrue(
            DB::table('user_notifications')->where('user_id', $client->id)->where('body', 'like', '%جلسة جديدة%')->exists(),
            'وبالجلسة الأولى عبر مسار الجدولة نفسه'
        );
        $this->actingAs($client)->get(route('cases.show', $case))
            ->assertInertia(fn ($p) => $p->where('case.najiz.caseNo', '4700123456')->where('case.najiz.circuit', 'الدائرة التجارية الأولى'));
        $this->assertSame('4700123456', $case->fresh()->toCard()['najiz']['caseNo']);
    }

    public function test_the_steps_happen_in_order_only(): void
    {
        [$case, $lawyer] = $this->approvedCase();

        // القيد قبل الرفع ⇐ مرفوض
        $this->actingAs($lawyer)->post(route('lawyer.cases.najiz.register', $case), $this->registration())->assertStatus(422);

        // الرفع قبل اعتماد اللائحة ⇐ مرفوض
        $case->update(['pleading_status' => 'pending_lawyer']);
        $this->actingAs($lawyer)->post(route('lawyer.cases.najiz.file', $case), ['request_no' => 'NJ-1', 'filed_at' => now()->toDateString()])->assertStatus(422);
        $case->update(['pleading_status' => 'approved']);

        // تاريخ رفعٍ في المستقبل ⇐ مرفوض، ورقمٌ فارغ كذلك
        $this->actingAs($lawyer)->post(route('lawyer.cases.najiz.file', $case), ['request_no' => '', 'filed_at' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors(['request_no', 'filed_at']);

        // بعد القيد لا تُعدَّل بيانات الرفع
        $this->actingAs($lawyer)->post(route('lawyer.cases.najiz.file', $case), ['request_no' => 'NJ-2', 'filed_at' => now()->toDateString()]);
        $this->actingAs($lawyer)->post(route('lawyer.cases.najiz.register', $case), $this->registration());
        $this->actingAs($lawyer)->post(route('lawyer.cases.najiz.file', $case), ['request_no' => 'NJ-3', 'filed_at' => now()->toDateString()])->assertStatus(422);
        $this->assertSame('NJ-2', $case->fresh()->najiz_request_no);
    }

    public function test_only_the_assigned_lawyer_files(): void
    {
        [$case] = $this->approvedCase();
        $stranger = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $this->actingAs($stranger)->post(route('lawyer.cases.najiz.file', $case), ['request_no' => 'NJ-9', 'filed_at' => now()->toDateString()])->assertForbidden();
        $this->assertSame('قيد التحضير', $case->fresh()->status);
    }

    public function test_approval_no_longer_claims_the_case_was_filed(): void
    {
        $src = (string) file_get_contents(app_path('Support/CasePleading.php'));

        $this->assertStringNotContainsString("'status' => 'منظورة'", $src, 'الاعتماد لا يكتب «منظورة»');
        $this->assertStringContainsString('وتُرفع عبر منصّة ناجز قريباً', $src);
    }
}
