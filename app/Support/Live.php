<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * بثّ التحديثات اللحظية كأثر جانبي أفضل-جهد.
 *
 * كل أحداث البثّ من نوع ShouldBroadcastNow، أي تُرسل شبكياً داخل طلب المستخدم نفسه.
 * وnداء broadcast() المجرّد يرمي BroadcastException إن تعذّر الوصول إلى Reverb، فيُجهض
 * ما يليه من كتابات: حدث فعلياً أن حجزاً اكتمل (استشارة + اجتماع Zoom + بطاقة تأكيد)
 * ثم فشل البثّ، فلم تُحدَّث حالة التذكرة وبقيت «بانتظار حجز الاستشارة».
 *
 * البثّ تحسينٌ للتجربة لا مصدرٌ للحقيقة: مصدر الحقيقة قاعدة البيانات، والعميل يرى
 * الحالة الصحيحة عند أي تحميل. لذا يُسجَّل الفشل ولا يُرمى — بنفس عرف ZoomService.
 */
class Live
{
    public static function push(object ...$events): void
    {
        foreach ($events as $event) {
            try {
                broadcast($event);
            } catch (\Throwable $e) {
                Log::warning('Live broadcast failed: '.$event::class.' — '.$e->getMessage());
            }
        }
    }
}
