<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ExecutionDocumentStatus;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\ExecutionDocument;
use App\Models\User;
use App\Support\ExecFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **حالة مستند التنفيذ تعدادٌ لا نصّ** (قاعدة CLAUDE.md) — كانت «مطلوب/مرفوع/مقبول/مرفوض» تُكتب وتُقارَن
 * حرفيّاً، وشرطا «يقبل الرفع» و«بانتظار المراجعة» منسوخين بين النموذج وحارسَي المتحكّم.
 */
class ExecutionDocumentStatusEnumTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_card_flags_follow_the_enum_for_every_stored_value(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-DS-'.uniqid(), 'subject' => 'تنفيذ', 'sanad' => 'شيك',
            'stage' => 3, 'status' => ExecFlow::label(3), 'tone' => ExecFlow::tone(3),
        ]);

        $expect = [
            'مطلوب' => [true, false, false, 'b-amber'],
            'مرفوع' => [false, true, true, 'b-blue'],
            'مقبول' => [false, false, true, 'b-green'],
            'مرفوض' => [true, false, false, 'b-red'],
        ];
        foreach ($expect as $stored => [$upload, $review, $provided, $tone]) {
            $card = ExecutionDocument::create(['execution_id' => $exec->id, 'label' => 'سند', 'status' => $stored])->toData();
            $this->assertSame([$upload, $review, $provided, $tone], [$card['canUpload'], $card['canReview'], $card['provided'], $card['tone']], $stored);
        }
        $this->assertSame(['مطلوب', 'مرفوع', 'مقبول', 'مرفوض'], array_column(ExecutionDocumentStatus::cases(), 'value'), 'القيم المخزَّنة بلا ترحيل');
    }

    public function test_no_document_status_literal_is_left_in_its_code(): void
    {
        foreach (['Models/ExecutionDocument.php', 'Http/Controllers/ExecFlowController.php', 'Support/ExecService.php', 'Support/ExecutionCreation.php'] as $file) {
            $this->assertDoesNotMatchRegularExpression("/'(مطلوب|مرفوع|مقبول|مرفوض)'/u", (string) file_get_contents(app_path($file)), $file);
        }
    }
}
