<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Transitions\LegalCase\RecordRuling;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Setting;
use App\Models\User;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **مهلة الاستئناف إعدادٌ لا رقمٌ منقوش** (قرار المالك 2026-10-01) — كانت `addDays(30)` في `RecordRuling`
 * ونصوصُ «30 يوماً» في الشاشات، فلا تُضبط لنوع حكمٍ مهلتُه غير ذلك.
 */
class AppealDeadlineSettingTest extends TestCase
{
    use RefreshDatabase;

    private function rule(): LegalCase
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-APL-'.$client->id, 'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري', 'status' => CaseStatus::InCourt->value, 'tone' => 'b-blue',
        ]);
        Workflow::run(new RecordRuling, $case, $admin, ['ruling' => 'حكمت المحكمة بإلزام المدّعى عليه.']);

        return $case->fresh();
    }

    public function test_default_is_thirty_days_and_declared_in_cases_group(): void
    {
        $this->assertSame('cases', SettingsRegistry::field('appeal_deadline_days')['group']);
        $this->assertSame(30, SettingsRegistry::int('appeal_deadline_days'));

        $case = $this->rule();
        $this->assertSame(now()->addDays(30)->toDateString(), $case->appeal_deadline_at->toDateString());
        $this->assertStringContainsString('30 يوماً', (string) $case->update_text);
    }

    public function test_deadline_follows_the_setting(): void
    {
        Setting::put('appeal_deadline_days', 15);

        $case = $this->rule();
        $this->assertSame(now()->addDays(15)->toDateString(), $case->appeal_deadline_at->toDateString());
        $this->assertStringContainsString('15 يوماً', (string) $case->update_text);
    }

    public function test_screens_no_longer_hardcode_thirty_days(): void
    {
        foreach (['resources/js/lib/case-court.tsx', 'resources/js/pages/admin/reports.tsx'] as $file) {
            $this->assertDoesNotMatchRegularExpression('/30 ?يوماً/u', (string) file_get_contents(base_path($file)), $file);
        }
        $this->assertStringNotContainsString('addDays(30)', (string) file_get_contents(app_path('Domain/Journey/Transitions/LegalCase/RecordRuling.php')));
    }
}
