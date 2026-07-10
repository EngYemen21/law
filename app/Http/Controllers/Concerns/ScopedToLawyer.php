<?php

namespace App\Http\Controllers\Concerns;

/**
 * عزل المحامي — يمنع (403) وصول المحامي المباشر لأي تذكرة/قضية/طلب تنفيذ غير مُسنَد إليه.
 * القوائم مفلترة بـassigned_lawyer_id؛ هذا يسدّ الوصول المباشر عبر الرابط والإجراءات.
 */
trait ScopedToLawyer
{
    protected function guardAssigned(object $model): void
    {
        // الإدارة العليا لها صلاحيات مطلقة (إشراف كامل) — لا يقيّدها إسناد المحامي
        if (auth()->user()?->isAdmin()) {
            return;
        }

        abort_unless((int) $model->assigned_lawyer_id === (int) auth()->id(), 403);
    }
}
