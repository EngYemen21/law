<?php

namespace App\Http\Controllers\Concerns;

/**
 * عزل رؤية الموظف بحسب فرعه — يُستخدم في متحكمات الموظف لتصفية القوائم ومنع الوصول المباشر
 * (403) لأي سجل خارج فرع الموظف الحالي.
 */
trait BranchScoped
{
    /** فرع الموظف الحالي (مصدر العزل). */
    protected function currentBranch(): ?string
    {
        return auth()->user()?->branch;
    }

    /** يمنع (403) وصول الموظف لسجل يحمل فرعاً مختلفاً عن فرعه. الإدارة العليا مستثناة (صلاحيات مطلقة). */
    protected function guardBranch(object $model): void
    {
        if (auth()->user()?->isAdmin()) {
            return;
        }

        abort_unless($model->branch === auth()->user()?->branch, 403);
    }
}
