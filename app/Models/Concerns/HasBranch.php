<?php

namespace App\Models\Concerns;

use App\Models\Branch;

/**
 * ختم «الفرع» على سجلات العمل (تذاكر/قضايا/تنفيذ) عند الإنشاء إن لم يُحدَّد صراحةً:
 * فرع المحامي المسند إن وُجد، وإلا الفرع الافتراضي (المقرّ الرئيسي).
 * الإسناد الفعلي يضبط الفرع صراحةً عبر TicketAssignment؛ هذا شبكة أمان تمنع سجلاً بلا فرع.
 */
trait HasBranch
{
    public static function bootHasBranch(): void
    {
        static::creating(function ($model): void {
            if (empty($model->branch)) {
                $model->branch = optional($model->assignedLawyer)->branch ?: Branch::defaultName();
            }
        });
    }
}
