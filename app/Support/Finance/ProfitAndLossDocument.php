<?php

namespace App\Support\Finance;

use App\Support\ReportPrint;

/**
 * **التقرير الماليّ مُصدَّراً** — PDF (`ReportPrint` بجداوله) وCSV من حمولة `ProfitAndLoss::report`
 * بعينها، فلا يختلف المُصدَّر عن الشاشة. والصفوف نفسها للصيغتين (`sections`) فلا تفترقان.
 */
final class ProfitAndLossDocument
{
    /** @param  array<string, mixed>  $r */
    public static function html(array $r): string
    {
        $blocks = [];
        foreach (self::sections($r, fn (int $h) => VoucherFormat::sar($h)) as $i => $section) {
            $blocks[] = ['title' => self::ordinal($i + 1).'. '.$section['title'], 'table' => [
                'head' => $section['head'],
                'rows' => $section['rows'],
                'ltr' => range(1, count($section['head']) - 1),
            ]];
        }

        $notes = ['المبالغ قبل ضريبة القيمة المضافة. الإيراد عند التحصيل، والمصروفات المعتمدة وحدها، والصرف غير الملغى.'];
        if ($r['undatedPaid'] > 0) {
            $notes[] = "{$r['undatedPaid']} فاتورة مدفوعة بلا تاريخ سداد لم تدخل التقرير.";
        }
        if ($r['pendingExpenses'] > 0) {
            $notes[] = "{$r['pendingExpenses']} مصروف بانتظار الاعتماد في الفترة لم يُحسب.";
        }

        return ReportPrint::html([
            'title' => 'التقرير الماليّ — الأرباح والخسائر',
            'subtitle' => $r['period']['label'].' · مقارنةً بـ '.$r['previous']['label'],
            'ref' => 'PL-'.str_replace('-', '', $r['period']['from']).'-'.str_replace('-', '', $r['period']['to']),
            'blocks' => $blocks,
            'note' => implode(' ', $notes),
        ]);
    }

    /**
     * صفوف CSV — أرقامٌ بالريال بمنزلتين بلا رمز العملة، لتُحسب في الجداول.
     *
     * @param  array<string, mixed>  $r
     * @return list<list<string>>
     */
    public static function csv(array $r): array
    {
        $lines = [['التقرير الماليّ', $r['period']['from'].' — '.$r['period']['to'], 'مقارنةً بـ', $r['previous']['from'].' — '.$r['previous']['to']]];
        foreach (self::sections($r, fn (int $h) => number_format($h / 100, 2, '.', '')) as $section) {
            $lines[] = [];
            $lines[] = [$section['title']];
            $lines[] = $section['head'];
            array_push($lines, ...$section['rows']);
        }

        return $lines;
    }

    /**
     * أقسام التقرير بصفوفها — مصدرٌ واحد للصيغتين.
     *
     * @param  array<string, mixed>  $r
     * @param  callable(int): string  $money
     * @return list<array{title: string, head: list<string>, rows: list<list<string>>}>
     */
    private static function sections(array $r, callable $money): array
    {
        $cur = $r['summary']['current'];
        $prev = $r['summary']['previous'];
        $pct = fn (int $c, int $p) => ($x = ProfitAndLoss::change($c, $p)) === null ? '—' : ($x > 0 ? '+' : '').$x.'%';
        $compareRow = fn (string $label, int $c, int $p) => [$label, $money($c), $money($p), $pct($c, $p)];
        $compareHead = ['البند', 'الفترة', 'السابقة', 'التغيّر'];
        $breakdown = fn (array $rows) => $rows === []
            ? [['لا شيء في الفترتين', '', '', '']]
            : array_values(array_map(fn ($row) => $compareRow((string) $row['label'], (int) $row['current'], (int) $row['previous']), $rows));

        return [
            ['title' => 'الأرباح والخسائر', 'head' => $compareHead, 'rows' => [
                $compareRow('صافي الإيرادات المحصَّلة', $cur['revenue'], $prev['revenue']),
                $compareRow('صافي المصروفات المعتمدة', $cur['expenses'], $prev['expenses']),
                $compareRow('مستحقّات الموظّفين المصروفة', $cur['payouts'], $prev['payouts']),
                $compareRow($cur['profit'] >= 0 ? 'صافي الربح' : 'صافي الخسارة', $cur['profit'], $prev['profit']),
            ]],
            ['title' => 'الإيرادات بالنوع', 'head' => $compareHead, 'rows' => $breakdown($r['revenueByKind'])],
            ['title' => 'المصروفات بالتصنيف', 'head' => $compareHead, 'rows' => $breakdown($r['expensesByCategory'])],
            ['title' => 'مستحقّات الموظّفين بالبند', 'head' => $compareHead, 'rows' => $breakdown($r['payoutsByKind'])],
            ['title' => 'الأشهر', 'head' => ['الشهر', 'الإيرادات', 'المصروفات', 'الموظّفون', 'الربح'], 'rows' => array_values(array_map(
                fn ($m) => [(string) $m['label'], $money((int) $m['revenue']), $money((int) $m['expenses']), $money((int) $m['payouts']), $money((int) $m['profit'])],
                $r['months'],
            ))],
            ['title' => 'ضريبة القيمة المضافة (خارج الأرباح)', 'head' => $compareHead, 'rows' => [
                $compareRow('ضريبة المخرجات المحصَّلة', $cur['revenueVat'], $prev['revenueVat']),
                $compareRow('ضريبة المدخلات على المصروفات', $cur['expensesVat'], $prev['expensesVat']),
            ]],
        ];
    }

    private static function ordinal(int $n): string
    {
        return strtr((string) $n, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }
}
