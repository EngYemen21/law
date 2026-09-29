<?php

namespace App\Support\Finance;

use App\Models\User;
use App\Support\ReportPrint;
use Carbon\CarbonImmutable;

/**
 * **كشف حساب العميل مطبوعاً** — أرقام `ClientStatement::build` بعينها (فلا يختلف المطبوع عن الشاشة)
 * بتصميم مستندات المكتب (`ReportPrint`)، والحركات جدولاً برصيدٍ جارٍ يبدأ بالرصيد الافتتاحيّ.
 */
final class ClientStatementDocument
{
    /** @param  array{from: string, to: string, opening: int, closing: int, debit: int, credit: int, rows: list<array{date: string, kind: string, ref: string, description: string, debit: int, credit: int, balance: int}>}  $s */
    public static function html(User $client, array $s): string
    {
        $from = CarbonImmutable::parse($s['from']);
        $to = CarbonImmutable::parse($s['to']);
        $amount = fn (int $h) => $h === 0 ? '' : VoucherFormat::sar($h);

        $rows = [['', 'رصيد افتتاحيّ', '', '', '', ClientStatement::balanceLabel($s['opening'])]];
        foreach ($s['rows'] as $r) {
            $rows[] = [$r['date'], $r['kind'].' — '.$r['description'], $r['ref'], $amount($r['debit']), $amount($r['credit']), ClientStatement::balanceLabel($r['balance'])];
        }

        return ReportPrint::html([
            'title' => 'كشف حساب عميل',
            'subtitle' => 'من '.VoucherFormat::date($from).' إلى '.VoucherFormat::date($to),
            'ref' => 'SOA-'.$client->id.'-'.$to->format('Ymd'),
            'blocks' => [
                ['title' => '١. بيانات المكتب', 'cellRows' => [TaxInvoiceDocument::officeCells(CarbonImmutable::now())]],
                ['title' => '٢. العميل', 'cellRows' => [array_values(array_filter([
                    ['الاسم', $client->name],
                    $client->phone ? ['الجوال', (string) $client->phone] : null,
                    $client->national_id ? ['رقم الهوية', (string) $client->national_id] : null,
                ]))]],
                ['title' => '٣. ملخّص الفترة', 'cellRows' => [[
                    ['الرصيد الافتتاحيّ', ClientStatement::balanceLabel($s['opening'])],
                    ['إجمالي ما عليه', VoucherFormat::sar($s['debit'])],
                    ['إجمالي ما له', VoucherFormat::sar($s['credit'])],
                    ['الرصيد الختاميّ', ClientStatement::balanceLabel($s['closing'])],
                ]]],
                ['title' => '٤. الحركات', 'table' => [
                    'head' => ['التاريخ', 'البيان', 'المرجع', 'عليه', 'له', 'الرصيد'],
                    'rows' => $rows,
                    'foot' => ['', 'الإجمالي', '', VoucherFormat::sar($s['debit']), VoucherFormat::sar($s['credit']), ClientStatement::balanceLabel($s['closing'])],
                    'ltr' => [0, 2, 3, 4, 5],
                ]],
            ],
            'note' => 'المبالغ شاملة ضريبة القيمة المضافة كما صدرت بها الفواتير. «عليه» ما صدر على العميل، و«له» ما سدّده أو أُسقط عنه؛ والرصيد الموجب مستحقٌّ على العميل.',
        ]);
    }
}
