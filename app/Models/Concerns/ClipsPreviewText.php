<?php

namespace App\Models\Concerns;

/**
 * يقصّ أعمدة المعاينة عند حدّ عرضها قبل الحفظ.
 *
 * لماذا: `tickets.last_message` و`cases.update_text` و`executions.last_action` أعمدة
 * `varchar(255)` تعرض سطر المعاينة في بطاقات القوائم، لكنّها تستقبل نصّ المستخدم كما هو —
 * تفاصيل فتح التذكرة وردود العميل والموظف والمحامي، وكلّها بلا سقف عملي. ومع
 * `'strict' => true` في اتصال mysql ترمي القاعدة `SQLSTATE[22001] Data too long` بدل أن
 * تقصّ، **فيفشل الإدراج كلّه**: العميل يكتب طلبه فيضيع بلا تذكرة ولا رسالة مفهومة.
 *
 * ولماذا هنا لا عند مواضع الكتابة: للأعمدة الثلاثة نحو ثلاثين موضع كتابة موزّعة على
 * متحكّمات ومهام وفئات دعم (ستّة في TicketTriage وحدها)، وتُضاف مواضع جديدة باستمرار.
 * نقطة اختناق واحدة على النموذج لا تُنسى؛ ثلاثون إصلاحاً موضعياً تُنسى.
 *
 * ولماذا القصّ لا توسيع العمود: النصّ الكامل محفوظ أصلاً في جدول الرسائل ويُقرأ منه في
 * شاشة المحادثة. توسيع عمود المعاينة إلى TEXT يُثقل كل استعلام قائمة ببيانات لا تُعرض
 * كاملة أبداً.
 *
 * الاستعمال: `protected array $previewText = ['last_message'];`
 */
trait ClipsPreviewText
{
    /** عرض varchar لأعمدة المعاينة في المهاجرات. */
    private const PREVIEW_LIMIT = 255;

    public function setAttribute($key, $value)
    {
        if (is_string($value) && in_array($key, $this->previewText ?? [], true)) {
            $value = self::clipPreview($value);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * يقصّ عند الحدّ مع «…» ختامية فيبدو النصّ مقتطعاً لا مبتوراً.
     * mb_substr لا substr: العربية متعدّدة البايتات، وقصّها بالبايتات يبتر المحرف الأخير
     * نصفين فيُخزَّن محرف تالف (وهو اصطلاح المشروع أصلاً — راجع Ticket::maskClient).
     */
    public static function clipPreview(string $value): string
    {
        if (mb_strlen($value) <= self::PREVIEW_LIMIT) {
            return $value;
        }

        return mb_substr($value, 0, self::PREVIEW_LIMIT - 1).'…';
    }
}
