<?php

namespace App\Support;

/**
 * **بيئة التشغيل — المصدر الواحد لـ«هل هذا صندوق تجربة؟»** (فصل البيئات، المرحلة ١ — 2026-09-29).
 *
 * كانت الحمايات معلّقةً بـ`isProduction()` أي بـ`APP_ENV === 'production'` حرفيّاً: فأيّ قيمةٍ أخرى (`prod`،
 * خطأٌ إملائيّ، فراغ) كانت تفتح إعادة ضبط القاعدة وترخي قواعد كلمات المرور. هنا **قائمة سماح**: ما لم تُعلَن
 * البيئة صندوقَ تجربةٍ صراحةً فهي إنتاج. والميزات الخطرة (الرمز الثابت، تصفير القاعدة، البذور التجريبيّة)
 * تُطفأ خارج الصندوق وقت التشغيل — لا يسقط الموقع — و`php artisan env:check` يكشف الإعداد الخاطئ صراحةً.
 */
final class AppEnvironment
{
    /** البيئات التي تُعدّ تجربةً: التطوير، والاختبارات الآليّة، والتجريبيّة (staging) حين تُنشأ. */
    public const SANDBOX = ['local', 'testing', 'staging'];

    public static function isSandbox(): bool
    {
        return in_array(app()->environment(), self::SANDBOX, true);
    }

    public static function isProduction(): bool
    {
        return ! self::isSandbox();
    }
}
