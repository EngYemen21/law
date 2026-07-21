<?php

namespace App\Rules;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * يتحقّق أن المعرّف المُمرَّر يشير إلى محامٍ نشط (دور Lawyer + status=active)،
 * وضمن فرع مُعطى إن طُلب (لعزل الموظف ضمن فرعه). يستخدم للعميل (بلا فرع: أي محامٍ نشط)
 * وللموظف (ضمن فرع الموظف الحالي).
 *
 * موحَّد عبر كل مسارات الحجز/التحويل لمنع تمرير عميل/موظف/إداري كـlawyer_id.
 */
class LawyerInBranch implements ValidationRule
{
    public function __construct(private readonly ?string $branch = null) {}

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

        // فحص الفرع فقط إن طُلب (موظف ضمن فرعه). العميل لا يُقيَّد بفرع.
        if ($this->branch !== null && $lawyer->branch !== $this->branch) {
            $fail('المستشار المختار خارج فرعك الحالي.');

            return;
        }
    }
}
