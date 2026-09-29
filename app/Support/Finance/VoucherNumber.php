<?php

namespace App\Support\Finance;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * **رقم السند المحاسبيّ — متسلسلٌ لكلّ سنة** (`RV-2026-00001` للقبض، `PV-…` للصرف).
 *
 * خلاف `ReferenceNumber` العشوائيّ عن قصد: السند يُراجَع بتسلسله، والفجوة فيه سؤالٌ للمراجع.
 * التالي = أعلى رقمٍ في سنته + ١؛ والقيد الفريد على العمود يحسم تسابق طلبين، فيعيد المُصدِر
 * (`ReceiptVoucher::issue`) المحاولة برقمٍ جديد بدل أن يتكرّر رقم.
 */
final class VoucherNumber
{
    /** @param class-string<Model> $model */
    public static function next(string $prefix, string $model, string $column, CarbonInterface $at): string
    {
        $stem = $prefix.'-'.$at->format('Y').'-';

        // الأكبر عدداً لا نصّاً: ما تجاوز الخانات الخمس يبقى أكبر (سندات السنة آلافٌ لا ملايين)
        $serial = 1 + (int) $model::query()->where($column, 'like', $stem.'%')->pluck($column)
            ->map(fn ($number) => (int) substr((string) $number, strlen($stem)))
            ->max();

        return $stem.str_pad((string) $serial, 5, '0', STR_PAD_LEFT);
    }
}
