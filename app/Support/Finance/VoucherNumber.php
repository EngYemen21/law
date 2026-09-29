<?php

namespace App\Support\Finance;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * **رقم السند المحاسبيّ — متسلسلٌ لكلّ سنة** (`RV-2026-00001` للقبض، `PV-…` للصرف).
 *
 * خلاف `ReferenceNumber` العشوائيّ عن قصد: السند يُراجَع بتسلسله، والفجوة فيه سؤالٌ للمراجع.
 * التالي = أعلى رقمٍ في سنته **عبر كلّ مصادر الدفتر** + ١ — فدفتر سندات الصرف واحدٌ للمصروفات
 * وصرف مستحقّات الموظّفين معاً. والقيد الفريد على كلّ عمود يحسم تسابق طلبين، فيعيد المُصدِر
 * المحاولة برقمٍ جديد بدل أن يتكرّر رقم.
 */
final class VoucherNumber
{
    /** @param list<array{0: class-string<Model>, 1: string}> $sources [النموذج، العمود] لكلّ جدولٍ يحمل أرقام الدفتر */
    public static function next(string $prefix, array $sources, CarbonInterface $at): string
    {
        $stem = $prefix.'-'.$at->format('Y').'-';

        // الأكبر عدداً لا نصّاً: ما تجاوز الخانات الخمس يبقى أكبر (سندات السنة آلافٌ لا ملايين)
        $last = 0;
        foreach ($sources as [$model, $column]) {
            $last = max($last, (int) $model::query()->where($column, 'like', $stem.'%')->pluck($column)
                ->map(fn ($number) => (int) substr((string) $number, strlen($stem)))
                ->max());
        }

        return $stem.str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
    }
}
