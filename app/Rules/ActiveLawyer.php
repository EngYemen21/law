<?php

namespace App\Rules;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * يتحقّق أن المعرّف المُمرَّر يشير إلى محامٍ نشط (دور Lawyer + status=active).
 * موحَّد عبر كل مسارات الحجز/التحويل/الإسناد لمنع تمرير عميل/موظف/إداري كـlawyer_id.
 */
class ActiveLawyer implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            $fail('اختر محامياً صالحاً.');

            return;
        }

        $lawyer = User::find((int) $value);

        if (! $lawyer) {
            $fail('المستشار المختار غير موجود.');

            return;
        }

        if ($lawyer->role !== Role::Lawyer) {
            $fail('المعرّف المختار لا يشير إلى محامٍ.');

            return;
        }

        if (! $lawyer->isActive()) {
            $fail('المستشار المختار غير نشط حالياً.');

            return;
        }
    }
}
