<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **تاريخ الاستحقاق يُقرأ من التاريخ لا من نصٍّ مجمَّد.**
 *
 * 🔴 كانت الفاتورة المطبوعة (`InvoiceController::pdf`) تطبع `due_label` كما حُفظ لحظة الإصدار —
 * «خلال 3 أيام» — فيقرأه العميل بعد أسابيع تحت «تاريخ الاستحقاق». البطاقة كانت سليمة (تقرأ
 * `due_at`)، والمطبوعة وحدها تنحرف. صار للاثنين مصدرٌ واحد: `Invoice::dueDateText`.
 */
class InvoiceDueDateTextTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(array $extra = []): Invoice
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Invoice::create(array_merge([
            'user_id' => $client->id, 'number' => 'INV-DUE-'.uniqid(), 'description' => 'استشارة',
            'amount' => 575, 'status' => 'مستحقة', 'tone' => 'b-amber', 'paid' => false,
            'due_label' => 'خلال 3 أيام', 'due_at' => '2026-10-05',
        ], $extra));
    }

    public function test_the_real_date_wins_over_the_frozen_relative_text(): void
    {
        $text = $this->invoice()->dueDateText();

        $this->assertStringNotContainsString('خلال', (string) $text, 'لا نصّاً نسبيّاً مجمَّداً');
        $this->assertStringContainsString('2026', (string) $text);
        $this->assertStringContainsString('05', (string) $text);
    }

    /** السجلّات القديمة بلا تاريخ تبقى تعرض نصّها — لا شيءَ مُختلَق. */
    public function test_old_records_without_a_date_fall_back_to_their_text(): void
    {
        $this->assertSame('خلال 3 أيام', $this->invoice(['due_at' => null])->dueDateText());
        // العمود لا يقبل null — والفارغ لا يُعرض نصّاً فارغاً بل «لا تاريخ» فتكتب الفاتورة «—»
        $this->assertNull($this->invoice(['due_at' => null, 'due_label' => ''])->dueDateText());
    }

    /** البطاقة كما كانت: البادئة على التاريخ نفسه الذي تطبعه الفاتورة. */
    public function test_the_card_reads_the_same_date(): void
    {
        $invoice = $this->invoice(['due_at' => now()->addDays(3)->toDateString()]);

        $this->assertSame('تستحق قبل '.$invoice->dueDateText(), $invoice->toCard()['due']);
    }
}
