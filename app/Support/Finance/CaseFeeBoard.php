<?php

namespace App\Support\Finance;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Transitions\LegalCase\SetFee as SetFeeTransition;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * **لوحة أتعاب القضايا** (تطوير تبويب «أتعاب القضايا» 2026-09-29) — تبويباتٌ بحالة الأتعاب، وأرقام الرأس،
 * ومال كلّ قضيّة وفواتيرها. المال من تعريفات الفاتورة الواحدة (`Invoice::issued/collected/outstanding/overdue`)
 * لا حسابٍ جديد؛ وإجراءات الفاتورة (تحصيل · إلغاء · إعدام · الإثبات) تبقى في المالية ويُربط إليها.
 */
final class CaseFeeBoard
{
    /** التبويبات بترتيبها — ما ينتظر قرار الإدارة أوّلاً. المفاتيح قيم `fee_status` الحيّة إلّا `awaiting` و`all`. */
    public const TABS = [
        'awaiting' => 'بانتظار تحديد الأتعاب',
        'pending_payment' => 'بانتظار السداد',
        'installments' => 'مقسّطة',
        'paid' => 'مسدّدة',
        'waived' => 'بلا أتعاب',
        'all' => 'الكل',
    ];

    public static function tab(?string $tab): string
    {
        return isset(self::TABS[(string) $tab]) ? (string) $tab : 'all';
    }

    /**
     * القضايا في التبويب، مرتّبةً بما ينتظر تحديد الأتعاب أوّلاً ثمّ الأحدث، ومع البحث برقم القضيّة أو اسم العميل.
     *
     * @return Builder<LegalCase>
     */
    public static function query(string $tab, string $search): Builder
    {
        $q = LegalCase::query();

        if ($tab === 'awaiting') {
            self::awaiting($q);
        } elseif ($tab !== 'all') {
            $q->where('fee_status', $tab);
        }

        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('number', 'like', "%{$search}%")
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$search}%")));
        }

        return $q->orderByRaw('CASE WHEN fee_status = ? AND status = ? THEN 0 ELSE 1 END', ['none', CaseStatus::AwaitingFeeApproval->value])
            ->latest('id');
    }

    /** @return array<string, int> عدد القضايا في كلّ تبويب */
    public static function counts(): array
    {
        $byStatus = LegalCase::query()->selectRaw('fee_status, count(*) as c')->groupBy('fee_status')->pluck('c', 'fee_status');
        $out = [];

        foreach (array_keys(self::TABS) as $tab) {
            $out[$tab] = match ($tab) {
                'awaiting' => self::awaiting(LegalCase::query())->count(),
                'all' => (int) $byStatus->sum(),
                default => (int) ($byStatus[$tab] ?? 0),
            };
        }

        return $out;
    }

    /**
     * أرقام الرأس لفواتير الأتعاب كلّها: المفوتَر (ما عدا الملغاة)، والمحصَّل، والقائم على العملاء، والمتأخّر.
     *
     * @return array{invoiced:int, collected:int, outstanding:int, overdue:int, overdueCount:int}
     */
    public static function totals(): array
    {
        $fees = fn () => Invoice::query()->whereNotNull('case_id');

        return [
            'invoiced' => (int) $fees()->issued()->sum('amount'),
            'collected' => (int) $fees()->collected()->sum('amount'),
            'outstanding' => (int) $fees()->owedByClient()->sum('amount'),
            'overdue' => (int) $fees()->overdue()->sum('amount'),
            'overdueCount' => $fees()->overdue()->count(),
        ];
    }

    /**
     * مال القضيّة من فواتيرها المحمّلة (`invoices`) — بالتعريفات نفسها على الصفّ الواحد.
     *
     * @return array{invoiced:int, paid:int, remaining:int, overdue:bool, invoices:array<int, array<string, mixed>>}
     */
    public static function money(LegalCase $case): array
    {
        $out = ['invoiced' => 0, 'paid' => 0, 'remaining' => 0, 'overdue' => false, 'invoices' => []];

        foreach ($case->invoices->sortBy('id') as $invoice) {
            if (! $invoice instanceof Invoice) {
                continue;
            }

            $amount = (int) $invoice->amount;
            $overdue = $invoice->isOverdue();
            $out['invoiced'] += $invoice->isCancelled() ? 0 : $amount;
            $out['paid'] += $invoice->paid ? $amount : 0;
            $out['remaining'] += $invoice->isOwedByClient() ? $amount : 0;
            $out['overdue'] = $out['overdue'] || $overdue;
            $out['invoices'][] = [
                'no' => $invoice->number,
                'amount' => $amount,
                'installment' => $invoice->installment_no,
                'due' => $invoice->due_at?->toDateString(),
                'status' => $invoice->status,
                'tone' => InvoiceStatus::tryFrom((string) $invoice->status)?->tone() ?? 'b-grey',
                'overdue' => $overdue,
            ];
        }

        return $out;
    }

    /**
     * **حكم «تُحدَّد أتعابها الآن»** — حالة المصدر لانتقال `SetFee` وصلاحيّة الفاعل، ولم تُحدَّد بعد.
     * الموضع الواحد الذي تقرؤه صفحة الأتعاب وصفحة القضيّة؛ كانت الثانية تقارن نصّ الحالة العربيّ.
     */
    public static function canSetFee(LegalCase $case, ?User $actor): bool
    {
        $transition = new SetFeeTransition;

        return $case->fee_status === 'none'
            && $transition->accepts((string) $case->status)
            && $transition->deny($case, $actor) === null;
    }

    /**
     * @param  Builder<LegalCase>  $q
     * @return Builder<LegalCase>
     */
    private static function awaiting(Builder $q): Builder
    {
        return $q->where('fee_status', 'none')->whereIn('status', (new SetFeeTransition)->from());
    }
}
