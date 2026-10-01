<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ExecutionDecision;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\User;
use App\Support\ExecFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * **قرار دراسة التنفيذ تعدادٌ لا نصٌّ في المنطق** (قاعدة CLAUDE.md). كان «مرفوض»/«مقبول» يُقارَن ويُكتب حرفيّاً
 * في الخدمة والانتقالات والمهامّ ولوحة التوزيع ولوحة الإدارة، وحارس `close` ينسخ قاعدة `isRejectedOpen`.
 */
class ExecutionDecisionEnumTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_arabic_decision_literal_is_left_in_the_logic(): void
    {
        $offenders = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php')->notPath('Domain/Journey/Enums') as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                // حقل القرار نفسه: `->decision` أو عمود `'decision'` (حالة مراجعة المستند حقلٌ آخر)
                if (preg_match("/(->decision\\b|'decision')/", $line) && preg_match("/'(مرفوض|مقبول)'/u", $line)) {
                    $offenders[] = $file->getRelativePathname().':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_stored_values_are_unchanged_and_read_through_the_model(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-DC-'.uniqid(), 'subject' => 'تنفيذ', 'sanad' => 'شيك',
            'stage' => 3, 'status' => ExecFlow::label(3), 'tone' => ExecFlow::tone(3), 'decision' => 'مرفوض',
        ]);

        $this->assertSame('مرفوض', ExecutionDecision::Rejected->value, 'الصفوف القائمة تُقرأ بلا ترحيل');
        $this->assertTrue($exec->isRejectedAfterStudy());
        $this->assertTrue($exec->isRejectedOpen());

        $exec->update(['decision' => ExecutionDecision::Accepted->value]);
        $this->assertFalse($exec->fresh()->isRejectedAfterStudy());
    }
}
