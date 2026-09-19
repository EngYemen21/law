<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **بياناتُ القضايا — الدفعة ٥ من إصلاح مراحلها (2026-09-11).**
 *
 * هجرة `withheld_at` حجبت كلَّ مسودّات اللوائح القائمة، ومنها مسودّاتُ قضايا اعتُمدت لوائحها
 * بالزرّ القديم — فبقيت مخفيّةً عن أصحابها بلا إجراءٍ ينتظرها. والبذرة التجريبيّة تحمل قيماً
 * لا تعرفها الشاشات، وقضيّةً «قيد التحضير» لا لائحة لها تنتظر.
 */
class CaseDataFixesTest extends TestCase
{
    use RefreshDatabase;

    private function approvedCaseWithWithheldDraft(AiSource $source): LegalCase
    {
        $case = LegalCase::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'number' => 'CASE-REL-'.uniqid(),
            'type' => 'نزاع', 'status' => 'منظورة', 'tone' => 'b-blue', 'pleading_status' => 'approved',
        ]);
        AiRun::create([
            'task_type' => 'case.pleading', 'entity_type' => LegalCase::class, 'entity_id' => $case->id,
            'entity_ref' => $case->number, 'status' => AiRun::STATUS_NEEDS_REVIEW, 'source' => $source->value,
        ]);
        $case->messages()->create([
            'who' => 'ai', 'name' => 'المساعد القانوني', 'role' => 'مسودة اللائحة', 'body' => 'مسودّة', 'withheld_at' => now(),
        ]);

        return $case;
    }

    private function visibleToClient(LegalCase $case): bool
    {
        return in_array('مسودة اللائحة', $case->messages()->visibleTo(false)->pluck('role')->all(), true);
    }

    public function test_the_release_command_is_a_dry_run_unless_applied(): void
    {
        $case = $this->approvedCaseWithWithheldDraft(AiSource::AiSuccess);

        $this->artisan('cases:release-approved-pleadings')->assertSuccessful();
        $this->assertFalse($this->visibleToClient($case), 'بلا --apply لا يُغيَّر شيء');

        $this->artisan('cases:release-approved-pleadings', ['--apply' => true])->assertSuccessful();
        $this->assertTrue($this->visibleToClient($case), 'المعتمَدة تُطلَق لصاحبها');
    }

    public function test_the_release_command_never_releases_a_fallback_text(): void
    {
        $case = $this->approvedCaseWithWithheldDraft(AiSource::Fallback);

        $this->artisan('cases:release-approved-pleadings', ['--apply' => true])->assertSuccessful();
        $this->assertFalse($this->visibleToClient($case), 'نصٌّ احتياطيّ لا يُطلَق لائحةً');
    }

    public function test_the_demo_seed_uses_catalogue_values(): void
    {
        $seed = (string) file_get_contents(database_path('seeders/DemoDataSeeder.php'));

        foreach (["'fee_status' => 'مسددة بالكامل'", "'fee_status' => 'دفعة أولى مسددة'", "'fee_status' => 'بانتظار السداد'"] as $bad) {
            $this->assertStringNotContainsString($bad, $seed, 'قيمةٌ لا تعرفها الشاشات');
        }
        $this->assertStringContainsString("'status' => 'قيد التحضير',\n                'tone' => 'b-blue',", $seed);
        $this->assertStringContainsString("'pleading_status' => 'pending_lawyer',", $seed);
    }
}
