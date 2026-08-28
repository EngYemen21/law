<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\EventStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الجلسة الفائتة كانت طريقاً مسدوداً: الـcron يكتب «بانتظار تسجيل النتيجة» بينما زرّا
 * «منعقدة/مؤجلة» في صفحة القضية مشروطان بـ«مجدولة» — فيختفيان، والإشعار يقول
 * «سجّل نتيجتها من صفحة القضية»! وسلسلة موازية HEARING_LAPSED تجعل الشارة زرقاء.
 * التوحيد: سلسلة واحدة EventStatus::HEARING_LAPSED في الكتابة والعرض والشروط.
 */
class HearingLapseButtonsTest extends TestCase
{
    use RefreshDatabase;

    private function lapsedHearing(): array
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-HL-1', 'type' => 'تجاري',
            'assigned_lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'status' => 'منظورة', 'tone' => 'b-cyan',
        ]);
        $hearing = CaseHearing::create([
            'case_id' => $case->id, 'title' => 'جلسة مرافعة', 'day' => 'أمس', 'time' => '10:00 ص',
            'court' => 'المحكمة', 'status' => 'مجدولة', 'starts_at' => now()->subDays(2),
        ]);

        return [$lawyer, $case, $hearing];
    }

    /** الـcron يسم الفائتة بالسلسلة الموحَّدة — لا سلسلة ثانية تكسر الشروط والألوان. */
    public function test_auto_lapse_writes_the_unified_lapsed_status(): void
    {
        [, , $hearing] = $this->lapsedHearing();

        $this->artisan('hearings:auto-lapse')->assertExitCode(0);

        $this->assertSame(EventStatus::HEARING_LAPSED, $hearing->fresh()->status);
    }

    /** وتسجيل النتيجة يقبل الجلسة الموسومة فائتةً — الإشعار لم يعد يقود لطريق مسدود. */
    public function test_record_hearing_accepts_a_lapsed_hearing(): void
    {
        [$lawyer, $case, $hearing] = $this->lapsedHearing();
        $this->artisan('hearings:auto-lapse');

        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.record', [$case, $hearing]), [
            'status' => 'منعقدة',
            'outcome' => 'تم سماع البيّنات وحُجزت للحكم.',
        ])->assertRedirect();

        $this->assertSame('منعقدة', $hearing->fresh()->status);
    }
}
