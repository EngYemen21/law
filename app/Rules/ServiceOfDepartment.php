<?php

namespace App\Rules;

use App\Support\LegalCatalogue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * يتحقّق أنّ الخدمة المختارة **فعّالة وتتبع القسم المختار** — لا خدمةً من قسمٍ آخر ولا موقوفة.
 *
 * القسم يُمرَّر كما وصل في الطلب (معرّفاً أو اسماً)؛ إن لم يُطابَق قسمٌ تُترك الرسالة لقاعدة
 * `ActiveLegalDepartment` كي لا يرى المستخدم خطأين لسببٍ واحد.
 */
class ServiceOfDepartment implements ValidationRule
{
    public function __construct(private readonly mixed $department) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $department = LegalCatalogue::fromInput($this->department);
        if ($department === null) {
            return;
        }

        if (! is_numeric($value) || ! LegalCatalogue::serviceBelongs((int) $value, $department->id)) {
            $fail('الخدمة المختارة لا تتبع القسم المحدد أو لم تعد متاحة، يُرجى اختيارها من القائمة.');
        }
    }
}
