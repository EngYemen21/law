<?php

namespace App\Support;

/**
 * رفع مهلة تنفيذ الطلب للعمليات الطويلة (سلاسل الذكاء الاصطناعي، تصيير PDF، تنزيل تسجيلات Zoom).
 *
 * لماذا لا يُستدعى set_time_limit مباشرة: مهلة PHP على سطر الأوامر = 0 (بلا حدّ)، فالنداء
 * المباشر **يخفضها** بدل رفعها. النتيجة أن عامل الطابور يُقتل بخطأ فادح غير قابل للالتقاط
 * (لا failed() ولا release() ولا إعادة محاولة)، وأن تشغيل حزمة الاختبارات كاملةً يسقط
 * بـ«Maximum execution time exceeded» لأن العدّاد يُعاد ضبطه بمهلة قصيرة أثناء الاختبارات.
 *
 * القاعدة: على سطر الأوامر لا نلمس المهلة إطلاقاً، وعلى الويب نرفعها فقط إن كانت أقلّ.
 */
class WebTimeLimit
{
    public static function raise(int $seconds): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        $current = (int) ini_get('max_execution_time');
        if ($current === 0 || $current >= $seconds) {
            return; // بلا حدّ أصلاً، أو المهلة الحالية أوسع — لا نخفضها
        }

        @set_time_limit($seconds);
    }
}
