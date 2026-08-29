<?php

namespace App\Services\Ai;

/**
 * تجهيز ما يُرسَل إلى مزوّد خارجيّ: تمويه المعرّفات الشخصيّة وتصغير السياق.
 *
 * اليوم تُرسَل محادثات العملاء ونصوص مستنداتهم إلى Gemini/GLM **كما هي** — وهي
 * ملفّات قانونيّة تحمل أرقام هويّة وجوالات وآيبانات وبُرُداً. هذا الصنف يطبّق مبدأ
 * «أقلّ قدر لازم»: يُمرَّر ما تحتاجه المهمّة، ويُموَّه ما لا تحتاجه.
 *
 * **ما يُموَّه:** المعرّفات وحدها — رقم الهويّة/الإقامة، الجوال، الآيبان، البريد،
 * أرقام البطاقات. وهي **لا تدخل في أي استدلال قانونيّ**: صحّة سندٍ تنفيذيّ لا
 * تتوقّف على رقم جوال الموكّل.
 *
 * **ما لا يُموَّه:** أسماء الأطراف والمبالغ والتواريخ وموضوع النزاع — مادّة قانونيّة
 * لا معرّفات؛ تمويهها يُفسد التحليل نفسه لا يحميه. التمويه الزائد عطبٌ لا احتياط.
 */
final class AiContextBuilder
{
    public const ID_MASK = '[هوية]';

    public const PHONE_MASK = '[جوال]';

    public const IBAN_MASK = '[آيبان]';

    public const EMAIL_MASK = '[بريد]';

    public const CARD_MASK = '[بطاقة]';

    /**
     * أنماط المعرّفات بترتيب مقصود: الأطول والأكثر تحديداً أوّلاً كي لا يبتلع
     * نمطٌ عامّ جزءاً من معرّفٍ أخصّ (الآيبان يحوي أرقاماً، والبريد يحوي نقاطاً).
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        // آيبان سعوديّ: 24 خانة إجمالاً — SA ثم رقما تحقّق ثم 20 خانة.
        // (كان الحدّ 18 فلا يطابق آيباناً حقيقياً أصلاً — كشفه الاختبار)
        '/\bSA\d{2}[A-Z0-9]{18,22}\b/iu' => self::IBAN_MASK,
        '/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/u' => self::EMAIL_MASK,
        // بطاقة: 16 رقماً متّصلة أو مفصولة بمسافة/شرطة
        '/\b(?:\d[ -]?){15}\d\b/u' => self::CARD_MASK,
        // جوال سعوديّ بصيغه الثلاث (+966 / 00966 / 05)
        '/(?:\+?966|00966)5\d{8}\b/u' => self::PHONE_MASK,
        '/\b05\d{8}\b/u' => self::PHONE_MASK,
        // هويّة/إقامة: عشرة أرقام تبدأ بـ1 أو 2 (المبالغ تُنسَّق بفواصل فلا تلتبس)
        '/\b[12]\d{9}\b/u' => self::ID_MASK,
    ];

    /** تمويه المعرّفات وحدها — لا يمسّ الأسماء ولا المبالغ ولا التواريخ. */
    public static function mask(string $text): string
    {
        foreach (self::PATTERNS as $pattern => $mask) {
            $text = (string) preg_replace($pattern, $mask, $text);
        }

        return $text;
    }

    /**
     * تصغير السياق بحدٍّ معلن. القصّ عند حدّ الحروف لا البايتات (نصّ عربيّ)،
     * ويُذيَّل بعلامة صريحة كي لا يظنّ النموذج أن ما وصله كامل.
     */
    public static function clip(string $text, int $maxChars): string
    {
        if ($maxChars <= 0 || mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars)."\n[قُصّ النصّ عند {$maxChars} حرف]";
    }

    /** تنظيف محتوى غير موثوق: إزالة الوسوم، وتوحيد الفراغات، وتمويه، وقصّ. */
    public static function prepare(string $text, int $maxChars = 0): string
    {
        // محتوى script/style يُسقَط بجسمه: ليس مادّة قانونيّة، وتمريره للنموذج
        // ضجيجٌ في أحسن الأحوال وسطحُ حقنٍ في أسوئها.
        $clean = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#isu', ' ', $text);
        // الوسوم تُستبدَل بمسافة لا تُحذف: حذفها يلصق نصّين متجاورين فيبتلع الالتصاقُ
        // حدودَ الكلمات، فيفلت معرّفٌ من التمويه (كشفه الاختبار: جوال التصق بما بعده)
        $clean = (string) preg_replace('/<[^>]*>/u', ' ', $clean);
        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = (string) preg_replace('/[ \t]+/u', ' ', $clean);
        $clean = (string) preg_replace('/\n{3,}/u', "\n\n", $clean);

        return self::clip(self::mask(trim($clean)), $maxChars);
    }

    /**
     * إحصاء ما مُوّه — للتدقيق التشغيليّ بلا كشف القيم نفسها.
     *
     * @return array<string,int>
     */
    public static function maskCounts(string $text): array
    {
        $counts = [];
        foreach (self::PATTERNS as $pattern => $mask) {
            $found = preg_match_all($pattern, $text);
            if ($found) {
                $counts[$mask] = ($counts[$mask] ?? 0) + $found;
                $text = (string) preg_replace($pattern, $mask, $text);
            }
        }

        return $counts;
    }
}
