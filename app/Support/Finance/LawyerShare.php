<?php

namespace App\Support\Finance;

use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\SettingsRegistry;

/**
 * **نصيب المحامي من الأتعاب — القاعدة الواحدة للقضيّة والتنفيذ.**
 *
 * - **النسبة** تُحدَّد لكلّ ملفّ عند اعتماد أتعابه (`cases.lawyer_pct` · `executions.lawyer_pct`)، وافتراضها
 *   نسبة ملفّ المحامي (`users.pay_pct`)، وإلّا إعداد `lawyer_default_share_pct` (افتراضه `DEFAULT_PCT`).
 * - **النصيب الكلّيّ** = الأتعاب قبل الضريبة × النسبة.
 * - **المستحقّ** = المحصَّل فعلاً قبل الضريبة × النسبة، ولا يتجاوز النصيب الكلّيّ (قرار المالك: يُستحقّ عند
 *   سداد العميل وبقدر ما سدّد — فالأقساط تُحسب تلقائيّاً).
 *
 * كانت المعادلة مكتوبةً في `Admin\CaseController::setFee` وحده، ولا نصيب للتنفيذ أصلاً.
 */
final class LawyerShare
{
    /** **الافتراض المُعلَن** لـ`lawyer_default_share_pct` — كانت «20» منقوشةً في شاشة أتعاب القضايا. */
    public const DEFAULT_PCT = 20;

    /** النسبة الافتراضيّة لملفٍّ يُسند إلى هذا المحامي. */
    public static function defaultPctFor(?User $lawyer): int
    {
        $fallback = SettingsRegistry::int('lawyer_default_share_pct');
        if ($lawyer === null || ! $lawyer->isLawyer() || ! $lawyer->payType()?->hasPercent()) {
            return $fallback;
        }

        $pct = (int) round((float) $lawyer->pay_pct);

        return $pct > 0 ? min(100, $pct) : $fallback;
    }

    /** النسبة من مبلغ — `round` واحدٌ لكلّ حساب، فلا يختلف ريالٌ بين شاشة وكشف. */
    public static function of(int $amount, int $pct): int
    {
        return (int) round($amount * max(0, min(100, $pct)) / 100);
    }

    /**
     * يضبط نصيب المحامي على ملفّ التنفيذ عند اعتماد أتعابه — الانتقالان الإداريّان (`ApproveExecutionFee`
     * و`SetExecutionFee`) يناديانه فلا تتكرّر القاعدة. `$pct` null ⇒ نسبته المحفوظة ثمّ الافتراض.
     * والنموذج النسبيّ بلا مبلغ مقدَّم: `lawyer_fee` فارغٌ ويُحسب المستحقّ من المحصَّل فعلاً.
     */
    public static function applyToExecution(Execution $exec, ?int $pct): void
    {
        $exec->lawyer_pct = $pct ?? $exec->lawyer_pct ?? self::defaultPctFor($exec->assignedLawyer);
        $exec->lawyer_fee = $exec->feeMode() === 'percent' ? null : self::of((int) $exec->fee, (int) $exec->lawyer_pct);
    }

    /**
     * **صاحب نصيب الفاتورة لحظة تحصيلها** — المحامي المسند إلى قضيّتها أو ملفّ تنفيذها الآن.
     * يُجمَّد على الفاتورة (`share_user_id`) في `SettleInvoice`، فلا ينتقل ما حُصّل إلى محامٍ يُسند بعده.
     */
    public static function lawyerIdFor(Invoice $invoice): ?int
    {
        return match (true) {
            $invoice->case_id !== null => LegalCase::whereKey($invoice->case_id)->value('assigned_lawyer_id'),
            $invoice->exec_id !== null => Execution::whereKey($invoice->exec_id)->value('assigned_lawyer_id'),
            default => null,
        };
    }

    /** المستحقّ من المحصَّل — لا يتجاوز النصيب الكلّيّ إن عُرف (النموذج النسبيّ للتنفيذ بلا سقف). */
    public static function earned(int $collected, int $pct, ?int $cap = null): int
    {
        $earned = self::of($collected, $pct);

        return $cap === null ? $earned : min($cap, $earned);
    }
}
