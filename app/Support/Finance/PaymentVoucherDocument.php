<?php

namespace App\Support\Finance;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\StaffPayout;
use App\Models\User;
use App\Support\ReportPrint;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * **سند الصرف مطبوعاً** — قالبٌ واحد للمصروف ولقيد صرف مستحقّات الموظّف، بتصميم مستندات المكتب
 * (`ReportPrint`) وبيانات المكتب نفسها (`TaxInvoiceDocument::officeCells`)، والمبلغ رقماً وكتابةً.
 * والملغى يُطبع بحالته وسببه — السند لا يختفي من الدفتر.
 */
final class PaymentVoucherDocument
{
    public static function forExpense(Expense $expense): string
    {
        $creator = User::find($expense->created_by);
        $approver = $expense->approved_by !== null ? User::find($expense->approved_by) : null;

        return self::render(
            number: (string) $expense->voucher_no,
            status: $expense->status === ExpenseStatus::Voided ? 'ملغى — '.$expense->void_reason : $expense->status->label(),
            date: $expense->spent_on,
            payee: [['المستفيد', (string) ($expense->vendor ?: '—')]],
            halalas: $expense->amount_halalas,
            vatHalalas: $expense->vat_halalas,
            about: array_filter([
                ['التصنيف', $expense->category->label()],
                ['البيان', $expense->description],
                ['جهة الدفع', $expense->paidFromLabel()],
                $expense->reference ? ['المرجع', $expense->reference] : null,
            ]),
            signatures: array_filter([
                ['سجّله', $creator->name ?? '—'],
                $approver !== null ? ['اعتمده', $approver->name] : null,
            ]),
        );
    }

    public static function forPayout(StaffPayout $payout): string
    {
        $staff = User::find($payout->user_id);
        $recorder = User::find($payout->recorded_by);

        return self::render(
            number: (string) $payout->voucher_no,
            status: $payout->isVoided() ? 'ملغى — '.$payout->void_reason : 'صرف مستحقّات',
            date: $payout->paid_at,
            payee: array_filter([
                ['المستفيد', $staff->name ?? '—'],
                $staff?->national_id ? ['رقم الهوية', (string) $staff->national_id] : null,
            ]),
            halalas: $payout->amount * 100,
            vatHalalas: 0,
            about: array_filter([
                ['البند', $payout->kind->label()],
                ['عن شهر', StaffEarnings::monthLabel(CarbonImmutable::createFromFormat('!Y-m', $payout->period) ?: CarbonImmutable::now())],
                $payout->note ? ['ملاحظة', $payout->note] : null,
            ]),
            signatures: [['سجّله', $recorder->name ?? '—'], ['توقيع المستلِم', '']],
        );
    }

    /**
     * @param  list<array{0:string,1:string}>  $payee
     * @param  list<array{0:string,1:string}>  $about
     * @param  list<array{0:string,1:string}>  $signatures
     */
    private static function render(string $number, string $status, ?CarbonInterface $date, array $payee, int $halalas, int $vatHalalas, array $about, array $signatures): string
    {
        $amountRows = [
            [['المبلغ', VoucherFormat::sar($halalas)]],
            [['المبلغ كتابةً', ArabicAmount::riyals($halalas)]],
        ];
        if ($vatHalalas > 0) {
            $amountRows[] = [['منها ضريبة القيمة المضافة', VoucherFormat::sar($vatHalalas)]];
        }

        return ReportPrint::html([
            'title' => 'سند صرف',
            'subtitle' => $status,
            'ref' => $number,
            'blocks' => [
                ['title' => '١. بيانات المكتب', 'cellRows' => [TaxInvoiceDocument::officeCells($date)]],
                ['title' => '٢. صُرف إلى', 'cellRows' => [$payee]],
                ['title' => '٣. المبلغ', 'cellRows' => $amountRows],
                ['title' => '٤. وذلك عن', 'cellRows' => [$about]],
            ],
            'approval' => [
                'title' => 'الصرف',
                'rows' => [...$signatures, ['تاريخ الصرف', VoucherFormat::date($date)]],
            ],
            'note' => 'هذا السند إثباتٌ لصرف المبلغ المذكور أعلاه من المكتب.',
        ]);
    }
}
