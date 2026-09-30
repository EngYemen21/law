<?php

namespace App\Support\Finance;

use App\Models\Invoice;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * **خطّة التقسيط — مصدرٌ واحد للقضايا والتنفيذ.** كانت مكتوبةً مرّتين (`CaseFee` و`ExecFee`) بالحساب نفسه،
 * وبالعيوب نفسها (تدقيق الدفع 2026-09-30):
 *
 * - **الضريبة:** كانت كلّ حصّةٍ يُعاد حساب ضريبتها بنسبة **اليوم** وتقريبٍ مستقلّ، فيختلّ مجموع الضريبة بريال
 *   وتتغيّر نسبةُ فاتورةٍ صدرت. الآن يُقسَّم أساسُ الفاتورة الأمّ وضريبتُها المجمَّدان، والكسر في الأولى.
 * - **الاكتمال:** كان «المدفوع ≥ العدد المختوم»، فقسطٌ أُلغي أو أُعدم يُبقي الخطّة ناقصةً أبداً. الآن تكتمل حين
 *   لا يبقى فيها قسطٌ مستحقّ.
 * - **الصفر:** مبلغٌ أقلّ من عدد الأقساط كان يُصدر أقساطاً بصفر ريال لا تقبلها البوّابة — الآن لا تُفتح الخطّة.
 *
 * `$owner` عمود المالك على الفاتورة: `case_id` أو `exec_id`.
 */
final class InstallmentPlan
{
    /**
     * يعيد هيكلة الفاتورة الأمّ إلى الدفعة الأولى ويُصدر أخواتها. رابطُ البوّابة القديم صدر بالمبلغ الكامل، فيُمسح.
     *
     * @param  Closure(int, int): string  $label  وصف الدفعة (رقمها، العدد)
     * @return bool false إن كان المبلغ لا يتّسع لعدد الأقساط (ولا يُكتب شيء)
     */
    public static function open(Invoice $master, int $count, string $owner, Closure $label): bool
    {
        $money = $master->taxBreakdown();
        if ($count < 2 || $money['subtotal'] < $count) {
            return false;
        }

        $bases = self::shares($money['subtotal'], $count);
        $vats = self::shares($money['vat_amount'], $count);

        for ($n = 1; $n <= $count; $n++) {
            $part = [
                'amount' => $bases[$n - 1] + $vats[$n - 1],
                'subtotal' => $bases[$n - 1],
                'vat_rate' => $money['vat_rate'],
                'vat_amount' => $vats[$n - 1],
            ];
            $attributes = ['installment_no' => $n, 'description' => $label($n, $count), ...InvoiceDue::installment($n)];

            if ($n === 1) {
                $master->update([...$part, ...$attributes, 'gateway' => null, 'gateway_ref' => null]);
            } else {
                InvoiceFactory::fromMoney($part, [...$attributes, 'user_id' => $master->user_id, $owner => $master->getAttribute($owner)]);
            }
        }

        return true;
    }

    /** الدفعة التالية — الأقدم غير المدفوعة **بترتيب الخطّة**، لا فاتورةٌ تكميليّة صدرت بينها. */
    public static function next(string $owner, int $ownerId): ?Invoice
    {
        return self::installments($owner, $ownerId)->outstanding()
            ->orderBy('installment_no')->orderBy('id')->first();
    }

    /**
     * تقدّم الخطّة **من فواتيرها** لا من عدّادٍ يُزاد: المدفوع، والعدد الفعليّ (المدفوع + المستحقّ؛ الملغى
     * والمعدوم خارجه)، واكتمالها.
     *
     * @return array{paid: int, total: int, done: bool}
     */
    public static function progress(string $owner, int $ownerId): array
    {
        $paid = self::installments($owner, $ownerId)->where('paid', true)->count();
        $remaining = self::installments($owner, $ownerId)->outstanding()->count();

        return ['paid' => $paid, 'total' => $paid + $remaining, 'done' => $paid > 0 && $remaining === 0];
    }

    /** @return Builder<Invoice> */
    private static function installments(string $owner, int $ownerId): Builder
    {
        return Invoice::where($owner, $ownerId)->whereNotNull('installment_no');
    }

    /**
     * يقسم عدداً صحيحاً إلى حصصٍ مجموعها هو بالضبط — الكسر في الأولى.
     *
     * @return list<int>
     */
    private static function shares(int $total, int $count): array
    {
        $share = intdiv($total, $count);

        return [$total - $share * ($count - 1), ...array_fill(0, $count - 1, $share)];
    }
}
