<?php

namespace App\Support\Finance;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * **كشف حساب العميل** (المرحلة ج) — مشتقٌّ من الفواتير والمقبوضات بلا جدولٍ جديد.
 *
 * كلّ حركةٍ قيدٌ بتاريخه: **عليه** الفاتورة الصادرة (بتاريخ إصدارها)؛ و**له** كلّ دفعةٍ مقبوضة (بتاريخ
 * قبضها وسند قبضها)، وإلغاءُ فاتورةٍ صدرت ثمّ أُلغيت، وشطبُها ديناً معدوماً. المسوّدة لم تصل العميل فلا
 * قيد لها. والرصيد الافتتاحيّ = كلّ ما قبل بداية الفترة، والجاري بعد كلّ قيد، والختاميّ آخره.
 *
 * **بيانات ما قبل دفتر المدفوعات**: فواتير مدفوعة بلا صفٍّ مقبوضٍ في `payments` (سُدّدت قبل أن يُقيَّد
 * كلّ سداد) — لها قيدُ سدادٍ «بلا سند قبض» بتاريخ سدادها، وإلّا ظهرت مدفوعةٌ ديناً قائماً في الكشف.
 * وتاريخ ما لم يُحفظ تاريخه (إصدارٌ أو سدادٌ قديم) من إنشاء الصفّ أو تحديثه — تقريبٌ معلَن.
 *
 * المبالغ كلّها بالهللة: الفاتورة بالريال الصحيح (×١٠٠) والمقبوضات بالهللة أصلاً.
 */
final class ClientStatement
{
    /**
     * @return array{
     *   from: string, to: string, opening: int, closing: int, debit: int, credit: int,
     *   rows: list<array{date: string, kind: string, ref: string, description: string, debit: int, credit: int, balance: int}>
     * }
     */
    public static function build(User $client, CarbonInterface $from, CarbonInterface $to): array
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = CarbonImmutable::instance($to)->endOfDay();

        $entries = self::entries($client);

        $opening = 0;
        $rows = [];
        $debit = 0;
        $credit = 0;
        foreach ($entries as $entry) {
            if ($entry['at']->lt($from)) {
                $opening += $entry['debit'] - $entry['credit'];

                continue;
            }
            if ($entry['at']->gt($to)) {
                continue;
            }
            $debit += $entry['debit'];
            $credit += $entry['credit'];
            $rows[] = $entry;
        }

        $balance = $opening;
        $out = [];
        foreach ($rows as $row) {
            $balance += $row['debit'] - $row['credit'];
            $out[] = [
                'date' => $row['at']->toDateString(),
                'kind' => $row['kind'],
                'ref' => $row['ref'],
                'description' => $row['description'],
                'debit' => $row['debit'],
                'credit' => $row['credit'],
                'balance' => $balance,
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening' => $opening,
            'closing' => $balance,
            'debit' => $debit,
            'credit' => $credit,
            'rows' => $out,
        ];
    }

    /** «1,150.00 ر.س عليه» / «… له» / «0.00 ر.س» — الموجب مستحقٌّ على العميل. */
    public static function balanceLabel(int $halalas): string
    {
        return VoucherFormat::sar(abs($halalas)).match (true) {
            $halalas > 0 => ' عليه',
            $halalas < 0 => ' له',
            default => '',
        };
    }

    /**
     * كلّ قيود العميل مرتّبةً بتاريخها (وفي اللحظة نفسها يسبق ما عليه ما له — الفاتورة قبل سدادها).
     *
     * @return list<array{at: CarbonImmutable, kind: string, ref: string, description: string, debit: int, credit: int}>
     */
    private static function entries(User $client): array
    {
        $invoices = Invoice::where('user_id', $client->id)
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->get();

        $received = Payment::received()->whereIn('invoice_id', $invoices->modelKeys())->get()->groupBy('invoice_id');

        $entries = [];
        foreach ($invoices as $invoice) {
            $halalas = (int) $invoice->amount * 100;
            $issuedAt = self::at($invoice->issued_at ?? $invoice->created_at);
            $entries[] = self::entry($issuedAt, 'فاتورة', (string) $invoice->number, (string) $invoice->description, $halalas, 0);

            $payments = $received->get($invoice->id, collect());
            foreach ($payments as $payment) {
                $entries[] = self::entry(
                    self::at($payment->received_at ?? $payment->reconciled_at ?? $payment->created_at),
                    'سند قبض',
                    (string) ($payment->receipt_no ?? '—'),
                    'سداد الفاتورة '.$invoice->number.' — '.$payment->methodLabel(),
                    0,
                    (int) $payment->amount_halalas,
                );
            }

            // سُدّدت (أو سُدّد باقيها) قبل دفتر المدفوعات: قيدُ سدادٍ بلا سندٍ بالباقي، لا دينٌ قائمٌ كاذب
            $unreceipted = $halalas - (int) $payments->sum('amount_halalas');
            if ($invoice->paid && $unreceipted > 0) {
                $entries[] = self::entry(self::at($invoice->paid_at ?? $invoice->updated_at), 'سداد', (string) $invoice->number, 'سداد الفاتورة '.$invoice->number.' (بلا سند قبض — قبل دفتر المدفوعات)', 0, $unreceipted);
            }

            if ($invoice->status === InvoiceStatus::Cancelled->value) {
                $entries[] = self::entry(self::at($invoice->cancelled_at ?? $invoice->updated_at), 'إلغاء فاتورة', (string) $invoice->number, 'إلغاء الفاتورة '.$invoice->number, 0, $halalas);
            }

            if ($invoice->status === InvoiceStatus::WrittenOff->value) {
                $entries[] = self::entry(self::at($invoice->written_off_at ?? $invoice->updated_at), 'شطب دين', (string) $invoice->number, 'شطب الفاتورة ديناً معدوماً', 0, $halalas);
            }
        }

        usort($entries, fn (array $a, array $b) => [$a['at']->timestamp, $b['debit']] <=> [$b['at']->timestamp, $a['debit']]);

        return $entries;
    }

    /** @return array{at: CarbonImmutable, kind: string, ref: string, description: string, debit: int, credit: int} */
    private static function entry(CarbonImmutable $at, string $kind, string $ref, string $description, int $debit, int $credit): array
    {
        return compact('at', 'kind', 'ref', 'description', 'debit', 'credit');
    }

    private static function at(?CarbonInterface $at): CarbonImmutable
    {
        return $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();
    }
}
