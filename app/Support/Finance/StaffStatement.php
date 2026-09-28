<?php

namespace App\Support\Finance;

use App\Models\User;
use App\Support\ReportPrint;

/**
 * **كشف مستحقّات الشهر للموظّف** — وثيقة `ReportPrint` من أرقام `StaffEarnings::for()` بعينها، فلا
 * يختلف الكشف عمّا تعرضه صفحة «مستحقاتي» ودرج الإدارة.
 */
final class StaffStatement
{
    public static function html(User $user, array $e): string
    {
        $sar = fn (?int $n) => $n === null ? '—' : number_format($n).' ر.س';
        $t = $e['totals'];

        $kinds = array_values(array_filter($t['byKind'], fn ($k) => $k['earned'] || $k['paid']));
        $blocks = [
            ['title' => 'بيانات الموظّف', 'cellRows' => [
                [['الاسم', $user->name], ['الصفة', $user->job_title ?: $user->role->label()]],
                [['نوع الأجر', $e['payLabel']], ['بداية السجلّ', $e['ledgerStartLabel']]],
            ]],
            ['title' => "ملخّص {$e['monthLabel']}", 'cellRows' => [
                [['مستحقّ الشهر', $sar($t['monthEarned'])], ['المصروف عن الشهر', $sar($t['monthPaid'])]],
                [['إجمالي المصروف', $sar($t['paid'])], ['الرصيد المتبقّي', $sar($t['balance'])]],
            ]],
        ];

        if ($kinds !== []) {
            $blocks[] = ['title' => 'البنود منذ بداية السجلّ', 'cellRows' => array_map(
                fn ($k) => [[$k['label'].' — المستحقّ', $sar($k['earned'])], ['المصروف', $sar($k['paid'])], ['المتبقّي', $sar($k['balance'])]],
                $kinds,
            )];
        }

        $monthShares = array_values(array_filter($e['shares'], fn ($r) => $r['monthEarned'] > 0));
        if ($monthShares !== []) {
            $blocks[] = ['title' => "نصيب الأتعاب المستحقّ في {$e['monthLabel']}", 'list' => array_map(
                fn ($r) => ($r['kind'] === 'case' ? 'قضيّة ' : 'تنفيذ ')."{$r['ref']} — {$r['client']}: {$r['pct']}% من المحصَّل ⇐ ".$sar($r['monthEarned']),
                $monthShares,
            )];
        }

        $monthSessions = array_values(array_filter($e['sessions']['rows'], fn ($r) => $r['inMonth']));
        if ($monthSessions !== []) {
            $blocks[] = ['title' => 'الجلسات المنتهية في الشهر', 'list' => array_map(fn ($r) => "{$r['ref']} — {$r['date']}: ".$sar($r['amount']), $monthSessions)];
        }

        $monthPayouts = array_values(array_filter($e['payouts'], fn ($p) => $p['period'] === $e['month']));
        $blocks[] = ['title' => "الصرف عن {$e['monthLabel']}", 'list' => $monthPayouts === []
            ? ['لم يُسجَّل صرفٌ عن هذا الشهر.']
            : array_map(fn ($p) => "{$p['paidAt']} — {$p['kindLabel']}".($p['ref'] ? " ({$p['ref']})" : '').': '.$sar($p['amount']).($p['voided'] ? " — ملغى: {$p['voidReason']}" : ''), $monthPayouts),
        ];

        return ReportPrint::html([
            'title' => 'كشف مستحقّات الموظّف',
            'subtitle' => $e['monthLabel'],
            'ref' => 'PAY-'.str_replace('-', '', $e['month']).'-'.$user->id,
            'blocks' => $blocks,
            'note' => 'نصيب الأتعاب يُستحقّ بقدر ما سدّده العميل فعلاً قبل الضريبة؛ والرصيد يُحسب من بداية السجلّ.',
        ]);
    }
}
