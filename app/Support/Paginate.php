<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * شكل موحّد لنتائج الترقيم المُرسَلة إلى Inertia: { data, meta }.
 *
 * لماذا: جداول الإدارة كانت تُجلب كاملة بـget() بلا حدّ (صفر paginate في المشروع)،
 * فمع نموّ المكتب تتحوّل إلى استهلاك ذاكرة وحمولة Inertia بميغابايتات لكل تحميل صفحة.
 * الشكل ثابت كي يقرأه مكوّن Pagination الواحد في الواجهة، والفلاتر تُمرَّر عبر
 * withQueryString() فلا تضيع عند تنقّل الصفحات.
 */
class Paginate
{
    /**
     * @param  callable  $mapper  دالة تحويل الصفّ إلى شكل البطاقة
     * @return array{data:array,meta:array{current_page:int,last_page:int,per_page:int,total:int}}
     */
    public static function shape(LengthAwarePaginator $paginator, callable $mapper): array
    {
        return [
            'data' => collect($paginator->items())->map($mapper)->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
