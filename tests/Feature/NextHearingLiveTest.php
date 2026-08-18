<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «الجلسة القادمة» الحيّة — عمود next_hearing المخزّن لا يتحدّث بمرور الوقت،
 * فكانت جلسة الشهر الماضي تُعرض «قادمة»، والجلسة الفائتة تبقى «مجدولة» بلا وسم.
 */
class NextHearingLiveTest extends TestCase
{
    use RefreshDatabase;

    private function case_(): LegalCase
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return LegalCase::create([
            'user_id' => $client->id,
            'number' => 'CASE-'.uniqid(),
            'type' => 'نزاع تجاري',
            'status' => 'منظورة',
            'tone' => 'b-blue',
            'next_hearing' => 'الأربعاء 09 يوليو · 10:00 ص', // نص مخزّن قديم
        ]);
    }

    public function test_past_scheduled_hearing_is_not_next(): void
    {
        $case = $this->case_();
        $case->hearings()->create(['title' => 'جلسة ماضية', 'day' => '2026-08-01', 'status' => 'مجدولة', 'starts_at' => now()->subWeeks(2)]);
        $future = $case->hearings()->create(['title' => 'جلسة قادمة', 'day' => '2026-09-01', 'status' => 'مجدولة', 'starts_at' => now()->addWeek()]);

        $this->assertSame($future->id, $case->nextHearingLive()?->id);
        $this->assertStringContainsString($future->starts_at->locale('ar')->translatedFormat('l d F Y'), $case->nextHearingLabel());
    }

    public function test_only_past_hearings_yields_dash_not_stale_text(): void
    {
        $case = $this->case_();
        $case->hearings()->create(['title' => 'جلسة ماضية', 'day' => '2026-08-01', 'status' => 'مجدولة', 'starts_at' => now()->subWeek()]);

        // توجد جلسات لكن لا قادمة — «—» صادقة بدل نص الجلسة الماضية
        $this->assertNull($case->nextHearingLive());
        $this->assertSame('—', $case->nextHearingLabel());
        $this->assertSame('—', $case->toCard()['next']);
    }

    public function test_legacy_case_without_hearings_keeps_stored_text(): void
    {
        // سجلات مبذورة بنص حر بلا صفوف جلسات — لا تُمسح إلى «—»
        $case = $this->case_();

        $this->assertSame('الأربعاء 09 يوليو · 10:00 ص', $case->nextHearingLabel());
    }

    public function test_recorded_hearing_is_never_next_and_lapsed_flag_derives(): void
    {
        $case = $this->case_();
        $lapsed = $case->hearings()->create(['title' => 'فائتة', 'day' => '2026-08-01', 'status' => 'مجدولة', 'starts_at' => now()->subDay()]);
        $done = $case->hearings()->create(['title' => 'منعقدة', 'day' => '2026-08-02', 'status' => 'منعقدة', 'starts_at' => now()->subDays(2)]);

        $this->assertTrue($lapsed->isLapsed());
        $this->assertTrue($lapsed->toData()['lapsed']);
        $this->assertFalse($done->isLapsed()); // سُجّلت نتيجتها — ليست فائتة
        $this->assertNull($case->nextHearingLive());
    }
}
