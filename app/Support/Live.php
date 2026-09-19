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
    /**
     * **قاطعُ دائرة.** بعد أوّل فشلٍ لا يُعاد الاتصال بـReverb المتعطّل لكلّ حدث: كان كلّ بثٍّ ينتظر
     * ~٢٫٣ث حتى يفشل، فتسجيلُ قيد دعوى (رسالة + إشعار + حالة ×٢ + جلسة) تجاوز مهلة الطلب ٣٠ث وعاد
     * ٥٠٠ بعد أن كُتبت البيانات كلّها (قيسَ 2026-09-11). يُحفظ في الحاوية لا في متغيّرٍ ثابت: الحاوية
     * جديدةٌ لكلّ طلبٍ ولكلّ اختبار، والعامل الطويل يُعيد المحاولة بعد انقضاء المهلة.
     */
    private const DOWN_KEY = 'live.broadcast.down_at';

    private const COOLDOWN_SECONDS = 30;

    public static function push(object ...$events): void
    {
        foreach ($events as $event) {
            if (self::isDown()) {
                continue;
            }

            try {
                broadcast($event);
            } catch (\Throwable $e) {
                app()->instance(self::DOWN_KEY, microtime(true));
                Log::warning('Live broadcast failed: '.$event::class.' — '.$e->getMessage());
            }
        }
    }

    private static function isDown(): bool
    {
        if (! app()->bound(self::DOWN_KEY)) {
            return false;
        }

        return (microtime(true) - (float) app(self::DOWN_KEY)) < self::COOLDOWN_SECONDS;
    }
}
