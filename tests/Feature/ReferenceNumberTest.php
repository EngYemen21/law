<?php

namespace Tests\Feature;

use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\MeetRequest;
use App\Models\User;
use App\Support\InvoiceNumber;
use App\Support\ReferenceNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 🔴 ستّة مواضع كانت تولّد `PREFIX-YYYY-` + random_int(1,9999) مباشرةً على أعمدة unique
 * بلا إعادة محاولة. التصادم يُرمى QueryException داخل المعاملة بلا التقاط ⇒ 500 خام،
 * ولأنه ليس 422 لا تلتقطه الواجهة فيبدو العطل متقطّعاً.
 *
 * الخاصيّة المطلوبة ليست شكل الرقم بل أنّه **غير مستعمل** مهما ضاقت المساحة.
 */
class ReferenceNumberTest extends TestCase
{
    use RefreshDatabase;

    /**
     * يحجز أرقاماً موجودة مسبقاً. `$upTo = 9998` يملأ مساحة السنة فيُجبَر المولّد على
     * المدى الموسّع — وهو ثقيل، فيُستعمل لكيان واحد فقط ويكتفي الباقي بحجز خفيف يُثبت
     * تجنّب المحجوز (تشغيل الحزمة على دفعات يقتل العملية عند عشرات آلاف الإدراجات).
     */
    private function reserve(string $table, string $column, string $prefix, array $extra = [], int $upTo = 60): void
    {
        $year = now()->format('Y');
        $rows = [];

        for ($n = 1; $n <= $upTo; $n++) {
            $rows[] = $extra + [
                $column => $prefix.'-'.$year.'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 2000) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    public function test_case_number_never_collides(): void
    {
        $client = User::factory()->create();
        $this->reserve('cases', 'number', 'CASE', ['user_id' => $client->id, 'type' => 'تجاري'], upTo: 9998);

        $number = ReferenceNumber::next(LegalCase::class, 'number', 'CASE');

        $this->assertFalse(LegalCase::where('number', $number)->exists());
        $this->assertStringStartsWith('CASE-', $number);
    }

    public function test_execution_number_never_collides(): void
    {
        $client = User::factory()->create();
        $this->reserve('executions', 'number', 'EXE', [
            'user_id' => $client->id, 'subject' => 'تنفيذ', 'sanad' => 'حكم',
        ]);

        $number = ReferenceNumber::next(Execution::class, 'number', 'EXE');

        $this->assertFalse(Execution::where('number', $number)->exists());
        $this->assertStringStartsWith('EXE-', $number);
    }

    /**
     * مولّدا التنفيذ كانا منفصلين ومداهما متداخل (1-9999 مقابل 1000-9999) على نفس
     * العمود الفريد — أي أن أحدهما كان يصطدم بأرقام الآخر.
     */
    public function test_both_execution_paths_share_one_generator(): void
    {
        $client = User::factory()->create();
        $seen = [];

        for ($i = 0; $i < 40; $i++) {
            $number = ReferenceNumber::next(Execution::class, 'number', 'EXE');
            $this->assertNotContains($number, $seen, 'تكرّر رقم تنفيذ.');
            $seen[] = $number;

            Execution::create([
                'user_id' => $client->id, 'number' => $number,
                'subject' => 'تنفيذ', 'sanad' => 'حكم',
            ]);
        }

        $this->assertCount(40, array_unique($seen));
    }

    public function test_consult_ref_never_collides(): void
    {
        $client = User::factory()->create();
        $this->reserve('consults', 'ref', 'CN', [
            'user_id' => $client->id, 'subject' => 'استشارة', 'channel' => 'مرئية', 'lawyer' => 'مستشار',
        ]);

        $ref = ReferenceNumber::next(Consult::class, 'ref', 'CN');

        $this->assertFalse(Consult::where('ref', $ref)->exists());
        $this->assertStringStartsWith('CN-', $ref);
    }

    public function test_meet_request_ref_never_collides(): void
    {
        $client = User::factory()->create();
        $this->reserve('meet_requests', 'ref', 'MR', [
            'user_id' => $client->id, 'service' => 'خدمة', 'type' => 'اجتماع', 'sent_by' => 'المكتب',
            'day' => '2026-09-01', 'time' => '10:00',
        ]);

        $ref = ReferenceNumber::next(MeetRequest::class, 'ref', 'MR');

        $this->assertFalse(MeetRequest::where('ref', $ref)->exists());
        $this->assertStringStartsWith('MR-', $ref);
    }

    /** الغلاف القديم يبقى عاملاً — أربعة مستدعين يعتمدونه. */
    public function test_invoice_number_wrapper_still_works(): void
    {
        $number = InvoiceNumber::next();

        $this->assertStringStartsWith('INV-', $number);
        $this->assertFalse(Invoice::where('number', $number)->exists());
    }
}
