<?php

namespace App\Support\Finance;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Enums\PayoutKind;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\StaffPayout;
use App\Models\StaffSuspension;
use App\Models\User;
use App\Support\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * **مستحقّات الموظّف — الحساب الواحد** (قرار المالك 2026-09-28).
 *
 * تقرؤه صفحة «مستحقاتي» للمحامي والموظّف، ودرج «المستحقّات والصرف» عند الإدارة، وكشف الشهر PDF —
 * فلا يختلف رقمٌ بين ثلاثتها. البنود:
 *
 * - **الراتب** (`PayType::hasSalary`): `users.salary` لكلّ شهرٍ من بداية السجلّ (أو الالتحاق إن تأخّر).
 * - **نصيب القضايا والتنفيذ** (`LawyerShare`): المحصَّل قبل الضريبة × نسبة الملفّ، بقدر ما سُدّد.
 * - **أجر الجلسات** (`PayType::isSession`): استشارةٌ منتهية عقدها × `users.session_fee`، بتاريخ موعدها.
 *
 * **بداية السجلّ** (`payroll_start`): ما استُحقّ قبلها يُعرض ولا يدخل الرصيد — كان يُصرف خارج النظام.
 * **الرصيد** لكلّ بند = مستحقّه منذ البداية − مصروفه الساري (`StaffPayout::active`).
 */
final class StaffEarnings
{
    private CarbonImmutable $start;

    private CarbonImmutable $monthStart;

    private CarbonImmutable $monthEnd;

    /** @var Collection<int, StaffPayout> */
    private Collection $payouts;

    private function __construct(private readonly User $user, string $month)
    {
        $this->monthStart = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $this->monthEnd = $this->monthStart->endOfMonth();
        $this->start = self::ledgerStart();
        $this->payouts = StaffPayout::where('user_id', $user->id)
            ->with(['legalCase:id,number', 'execution:id,number'])
            ->orderByDesc('paid_at')->orderByDesc('id')->get();
    }

    /** الشهر المطلوب `Y-m` — وما لا يُقرأ شهراً يعود إلى الشهر الحاليّ. */
    public static function month(?string $month): string
    {
        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : now()->format('Y-m');
    }

    /** أوّل يومٍ في شهر بداية السجلّ. */
    public static function ledgerStart(): CarbonImmutable
    {
        return CarbonImmutable::parse(SettingsRegistry::date('payroll_start'))->startOfMonth();
    }

    /**
     * @return array{month:string, ledgerStart:string, payType:?string, payLabel:string, salary:array, shares:list<array>, sessions:array, payouts:list<array>, totals:array}
     */
    public static function for(User $user, ?string $month = null): array
    {
        return (new self($user, self::month($month)))->build();
    }

    private function build(): array
    {
        $salary = $this->salary();
        $shares = [...$this->caseShares(), ...$this->execShares()];
        $sessions = $this->sessions();

        $earned = [
            PayoutKind::Salary->value => array_sum(array_column($salary['months'], 'amount')),
            PayoutKind::CaseShare->value => $this->sum($shares, 'earnedInLedger', 'case'),
            PayoutKind::ExecShare->value => $this->sum($shares, 'earnedInLedger', 'exec'),
            PayoutKind::Session->value => array_sum(array_map(fn ($r) => $r['inLedger'] ? $r['amount'] : 0, $sessions['rows'])),
        ];
        $monthEarned = [
            PayoutKind::Salary->value => (int) (collect($salary['months'])->firstWhere('period', $this->monthStart->format('Y-m'))['amount'] ?? 0),
            PayoutKind::CaseShare->value => $this->sum($shares, 'monthEarned', 'case'),
            PayoutKind::ExecShare->value => $this->sum($shares, 'monthEarned', 'exec'),
            PayoutKind::Session->value => array_sum(array_map(fn ($r) => $r['inMonth'] ? $r['amount'] : 0, $sessions['rows'])),
        ];

        $active = $this->payouts->filter(fn (StaffPayout $p) => ! $p->isVoided());
        $byKind = [];
        foreach (PayoutKind::cases() as $kind) {
            $paid = (int) $active->where('kind', $kind)->sum('amount');
            $byKind[$kind->value] = [
                'label' => $kind->label(),
                'earned' => $earned[$kind->value],
                'paid' => $paid,
                'balance' => $earned[$kind->value] - $paid,
                'monthEarned' => $monthEarned[$kind->value],
            ];
        }

        return [
            'month' => $this->monthStart->format('Y-m'),
            'monthLabel' => self::monthLabel($this->monthStart),
            'ledgerStart' => $this->start->format('Y-m'),
            'ledgerStartLabel' => self::monthLabel($this->start),
            'payType' => $this->user->payType()?->value,
            'payLabel' => $this->user->payLabel(),
            'salary' => $salary,
            'shares' => $shares,
            'sessions' => $sessions,
            'payouts' => $this->payouts->map(fn (StaffPayout $p) => self::payoutRow($p))->values()->all(),
            'totals' => [
                'byKind' => $byKind,
                'earned' => array_sum($earned),
                'paid' => (int) $active->sum('amount'),
                'balance' => array_sum($earned) - (int) $active->sum('amount'),
                'monthEarned' => array_sum($monthEarned),
                'monthPaid' => (int) $active->where('period', $this->monthStart->format('Y-m'))->sum('amount'),
            ],
        ];
    }

    // ── الراتب ──

    private function salary(): array
    {
        $type = $this->user->payType();
        if ($type === null || ! $type->hasSalary() || (int) $this->user->salary <= 0) {
            return ['applies' => false, 'monthly' => 0, 'months' => []];
        }

        $from = $this->start;
        if ($this->user->join_date !== null && $this->user->join_date->copy()->startOfMonth()->greaterThan($from)) {
            $from = CarbonImmutable::parse($this->user->join_date)->startOfMonth();
        }
        $to = CarbonImmutable::now()->startOfMonth();
        $suspensions = $this->user->suspensions()->get(['starts_on', 'ends_on']);

        $months = [];
        for ($m = $to; $m->greaterThanOrEqualTo($from); $m = $m->subMonth()) {
            $period = $m->format('Y-m');
            // **أيّام الإيقاف لا راتب لها** — الراتب بنسبة أيّام الشهر التي كان فيها مفعّلاً
            $days = $m->daysInMonth;
            $suspended = self::suspendedDays($suspensions, $m, $m->endOfMonth());
            $amount = (int) round($this->user->salary * ($days - $suspended) / $days);
            $paid = (int) $this->payouts->filter(fn (StaffPayout $p) => ! $p->isVoided() && $p->kind === PayoutKind::Salary && $p->period === $period)->sum('amount');
            $months[] = [
                'period' => $period,
                'label' => self::monthLabel($m),
                'amount' => $amount,
                'suspendedDays' => $suspended,
                'paid' => $paid,
                'remaining' => max(0, $amount - $paid),
            ];
        }

        return ['applies' => true, 'monthly' => (int) $this->user->salary, 'months' => $months];
    }

    /**
     * أيّام الإيقاف داخل الفترة — تقاطع كلّ فترة إيقاف معها؛ والمفتوحة (`ends_on` فارغ) ممتدّةٌ ما دام
     * موقوفاً. ويوم التفعيل نفسه يومُ عمل (`ends_on` حصريّ).
     *
     * @param  Collection<int, StaffSuspension>  $suspensions
     */
    private static function suspendedDays(Collection $suspensions, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $suspensions->sum(function ($s) use ($from, $to) {
            $start = CarbonImmutable::parse($s->starts_on)->max($from->startOfDay());
            $end = $s->ends_on !== null ? CarbonImmutable::parse($s->ends_on)->subDay()->min($to->startOfDay()) : $to->startOfDay();

            return $end->lessThan($start) ? 0 : (int) $start->diffInDays($end) + 1;
        });
    }

    // ── نصيب القضايا والتنفيذ (قاعدة `LawyerShare` الواحدة) ──

    /** @return list<array> */
    private function caseShares(): array
    {
        return $this->filesOf(LegalCase::query())
            ->map(fn (LegalCase $c) => $this->shareRow('case', $c, (int) $c->lawyer_fee))
            ->all();
    }

    /** @return list<array> */
    private function execShares(): array
    {
        // النموذج النسبيّ بلا مبلغٍ مقدَّم ⇒ بلا سقف؛ والثابت سقفه نصيبه المعتمد
        return $this->filesOf(Execution::query())
            ->map(fn (Execution $e) => $this->shareRow('exec', $e, $e->feeMode() === 'percent' ? null : (int) $e->lawyer_fee))
            ->all();
    }

    /**
     * ملفّات الموظّف: المسندة إليه الآن، **أو** التي حُصّل منها شيءٌ باسمه قبل أن تُسند إلى غيره —
     * فلا يسقط من كشفه ما استحقّه في عهده.
     *
     * @template T of LegalCase|Execution
     *
     * @param  Builder<T>  $query
     * @return Collection<int, T>
     */
    private function filesOf($query): Collection
    {
        return $query->where('lawyer_pct', '>', 0)
            ->where(fn ($q) => $q->where('assigned_lawyer_id', $this->user->id)
                ->orWhereHas('invoices', fn ($i) => $i->collected()->where('share_user_id', $this->user->id)))
            ->with(['user:id,name', 'invoices' => fn ($q) => $q->collected()])
            ->orderByDesc('id')->get();
    }

    /**
     * صفّ ملفٍّ واحد. النصيب **تراكميّ على الملفّ كلّه** (فالسقف يُحترم مهما تعدّد المحامون والأشهر)،
     * ثمّ تُنسب زيادة كلّ دفعةٍ إلى صاحبها المجمَّد لحظة تحصيلها (`invoices.share_user_id`) — ولا يُحسب
     * ريالٌ مرّتين بين شهرين ولا بين محاميين.
     */
    private function shareRow(string $kind, LegalCase|Execution $file, ?int $cap): array
    {
        $pct = (int) $file->lawyer_pct;
        $current = $file->assigned_lawyer_id === $this->user->id;

        $total = 0;
        $collectedAll = 0;
        $steps = $file->invoices
            ->sortBy(fn (Invoice $i) => [($i->paid_at ?? $i->updated_at)?->getTimestamp(), $i->id])
            ->map(function (Invoice $i) use (&$total, &$collectedAll, $pct, $cap, $file) {
                $collectedAll += $i->taxBreakdown()['subtotal'];
                $after = LawyerShare::earned($collectedAll, $pct, $cap);
                $step = [
                    'at' => CarbonImmutable::parse($i->paid_at ?? $i->updated_at),
                    'owner' => $i->share_user_id ?? $file->assigned_lawyer_id,
                    'subtotal' => $i->taxBreakdown()['subtotal'],
                    'earned' => $after - $total,
                ];
                $total = $after;

                return $step;
            })
            ->filter(fn (array $step) => $step['owner'] === $this->user->id);

        $earnedWhere = fn (callable $when) => (int) $steps->filter(fn ($st) => $when($st['at']))->sum('earned');
        $earned = (int) $steps->sum('earned');
        $beforeLedger = $earnedWhere(fn ($at) => $at->lessThan($this->start));
        $kindEnum = $kind === 'case' ? PayoutKind::CaseShare : PayoutKind::ExecShare;
        $fileKey = $kind === 'case' ? 'case_id' : 'execution_id';
        $paid = (int) $this->payouts->filter(fn (StaffPayout $p) => ! $p->isVoided() && $p->kind === $kindEnum && $p->{$fileKey} === $file->id)->sum('amount');

        return [
            'kind' => $kind,
            'id' => $file->id,
            'ref' => $file->number,
            'client' => (string) $file->user?->name,
            'fee' => (int) $file->fee,
            'pct' => $pct,
            'share' => $cap,
            // المسند الآن أم محامٍ سابق حُصّل في عهده — المتوقَّع بعد التحصيل للمسند الآن وحده
            'current' => $current,
            'collected' => (int) $steps->sum('subtotal'),
            'earned' => $earned,
            'expected' => $cap !== null && $current ? max(0, $cap - $total) : null,
            'beforeLedger' => $beforeLedger,
            'earnedInLedger' => $earned - $beforeLedger,
            'monthEarned' => $earnedWhere(fn ($at) => $at->betweenIncluded($this->monthStart, $this->monthEnd)),
            'paid' => $paid,
            'balance' => $earned - $beforeLedger - $paid,
        ];
    }

    // ── أجر الجلسات ──

    private function sessions(): array
    {
        $type = $this->user->payType();
        $fee = (int) $this->user->session_fee;
        if ($type === null || ! $type->isSession() || $fee <= 0) {
            return ['applies' => false, 'fee' => 0, 'rows' => []];
        }

        $rows = Consult::where('status', ConsultStatus::Ended->value)
            ->whereHas('appointment', fn ($q) => $q->where('lawyer_id', $this->user->id)->whereNotNull('starts_at'))
            ->with('appointment:id,starts_at')
            ->get()
            ->sortByDesc(fn (Consult $c) => $c->appointment->starts_at)
            ->map(function (Consult $c) use ($fee) {
                $at = CarbonImmutable::parse($c->appointment->starts_at);

                return [
                    'ref' => $c->ref,
                    'date' => $at->format('Y-m-d'),
                    'amount' => $fee,
                    'inLedger' => $at->greaterThanOrEqualTo($this->start),
                    'inMonth' => $at->betweenIncluded($this->monthStart, $this->monthEnd),
                ];
            })->values()->all();

        return ['applies' => true, 'fee' => $fee, 'rows' => $rows];
    }

    // ── مساعدات ──

    /** @param list<array> $rows */
    private function sum(array $rows, string $key, string $kind): int
    {
        return array_sum(array_map(fn ($r) => $r['kind'] === $kind ? (int) $r[$key] : 0, $rows));
    }

    public static function payoutRow(StaffPayout $p): array
    {
        return [
            'id' => $p->id,
            'kind' => $p->kind->value,
            'kindLabel' => $p->kind->label(),
            'amount' => $p->amount,
            'period' => $p->period,
            'periodLabel' => self::monthLabel(CarbonImmutable::createFromFormat('!Y-m', $p->period)),
            'paidAt' => $p->paid_at->format('Y-m-d'),
            'ref' => $p->legalCase?->number ?? $p->execution?->number,
            'note' => $p->note,
            'voided' => $p->isVoided(),
            'voidReason' => $p->void_reason,
        ];
    }

    public static function monthLabel(CarbonImmutable $m): string
    {
        return $m->locale('ar')->translatedFormat('F Y');
    }
}
