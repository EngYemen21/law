<?php

namespace App\Rules;

use App\Support\LegalCatalogue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * يتحقّق أنّ القيمة تشير إلى **قسمٍ فعّال** في كتالوج الأقسام — معرّفاً، أو اسماً، أو اسماً بديلاً.
 *
 * موحَّد لنموذج التذكرة وتحويلها بين الأقسام: كان كلٌّ منها يقبل أيّ نصٍّ حتى ١٢٠ حرفاً،
 * فيُحفظ قسمٌ لا يعرفه الإسناد ولا المرشّحات. الرسائل موجّهة لمن يختار من قائمة.
 */
class ActiveLegalDepartment implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $department = LegalCatalogue::fromInput($value);

        if ($department === null) {
            $fail('القسم المختار غير موجود، يُرجى اختياره من القائمة.');

            return;
        }

        if (! $department->isActive()) {
            $fail('القسم المختار غير متاح حالياً، يُرجى اختيار قسمٍ آخر.');
        }
    }
}
