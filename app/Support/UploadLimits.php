<?php

namespace App\Support;

/**
 * **حدود حجم الرفع — المصدر الواحد** (قرار المالك 2026-09-30: ثابتٌ في الكود لا إعداد).
 *
 * كانت «10240» منقوشةً في تسعة متحكّمات و«2048» في ثلاثة، ونصوص الواجهة نسخٌ ثالثة. والحدّ **تقنيّ**: لا يعلو
 * ما يقبله PHP على الخادم (`upload_max_filesize` · `post_max_size`)، وإلّا رُفض الملفّ قبل التحقّق بلا رسالة —
 * فتغييره قرارٌ مع إعداد الخادم لا من شاشة. نظيره في الواجهة `resources/js/lib/upload-limits.ts`، ويحرس تطابقهما
 * `UploadLimitsTest`.
 */
final class UploadLimits
{
    /** مرفقات المحادثة والقضيّة والتذكرة وجلسات المحكمة والمصروفات. */
    public const ATTACHMENT_KB = 10240;

    /** المستند المطلوب من العميل وإثبات السداد — بحدّ `upload_max_filesize` الافتراضيّ (2M). */
    public const DOCUMENT_KB = 2048;

    /** قاعدة التحقّق `max:` بالكيلوبايت. */
    public static function rule(int $kb): string
    {
        return 'max:'.$kb;
    }

    /** «2 ميجابايت» — لرسالة الرفض. */
    public static function label(int $kb): string
    {
        return intdiv($kb, 1024).' ميجابايت';
    }
}
