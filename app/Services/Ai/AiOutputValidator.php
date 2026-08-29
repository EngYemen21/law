<?php

namespace App\Services\Ai;

/**
 * التحقّق الخادميّ المستقلّ من مخرجات النموذج — **لا يُوثَق بأن النموذج اتّبع التعليمات**.
 *
 * كان كل تحليل يفكّ JSON ويتحقّق يدوياً بشروط متفرّقة داخل `LegalAiService`، فتختلف
 * الصرامة من دالّة لأخرى: بعضها يقبل أي مصفوفة فيها `summary`، وبعضها يستبدل قيمة
 * غير مسموحة بأوّل خيار صامتاً. هذا الصنف مصدر واحد للعقود.
 *
 * القاعدة: قيمة خارج المسموح ⇒ **تُسقَط** ولا تُستبدَل بتخمين. الحقل الإلزاميّ الناقص
 * ⇒ فشل بنيويّ يعيد `null`، فيسقط المستهلك إلى مساره الاحتياطيّ الموسوم.
 */
class AiOutputValidator
{
    /**
     * نتيجة فرز تذكرة.
     *
     * @return array{department:string,priority:string,intent:string}|null
     */
    public static function ticketTriage(?array $data, string $fallbackDepartment = ''): ?array
    {
        if (! is_array($data) || trim((string) ($data['department'] ?? '')) === '') {
            return null;
        }

        return [
            'department' => (string) $data['department'],
            'priority' => self::oneOf($data['priority'] ?? null, ['عادية', 'عالية'], 'عادية'),
            'intent' => self::oneOf($data['intent'] ?? null, ['عادي', 'شكوى', 'استعجال'], 'عادي'),
        ];
    }

    /**
     * نتيجة تحليل طلب تنفيذ.
     *
     * @param  array<int,string>  $defaultProcedures
     * @return array{summary:string,missing:array<int,string>,procedures:array<int,string>}|null
     */
    public static function executionAnalysis(?array $data, array $defaultProcedures = []): ?array
    {
        if (! is_array($data) || trim((string) ($data['summary'] ?? '')) === '') {
            return null;
        }

        return [
            'summary' => (string) $data['summary'],
            'missing' => self::stringList($data['missing'] ?? null),
            'procedures' => self::stringList($data['procedures'] ?? null) ?: $defaultProcedures,
        ];
    }

    /**
     * نتيجة تحليل استشارة. `$roster` أسماء المحامين الحقيقيّين — اسم خارجها يُسقَط
     * ولا يُستبدَل بأوّل محامٍ (كان الاستبدال الصامت يُظهر ترشيحاً بلا تحليل خلفه).
     *
     * @param  array<int,string>  $roster
     * @return array{class:string,summary:string,lawyer:string,missing:array<int,string>}|null
     */
    public static function consultAnalysis(?array $data, array $roster): ?array
    {
        if (! is_array($data)
            || trim((string) ($data['class'] ?? '')) === ''
            || trim((string) ($data['summary'] ?? '')) === '') {
            return null;
        }

        return [
            'class' => (string) $data['class'],
            'summary' => (string) $data['summary'],
            'lawyer' => self::oneOf($data['lawyer'] ?? null, $roster, ''),
            'missing' => self::stringList($data['missing'] ?? null),
        ];
    }

    /** قيمة من قائمة مسموحة، وإلّا البديل المعلن (لا تخمين). */
    private static function oneOf(mixed $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? (string) $value : $default;
    }

    /**
     * قائمة نصوص منظّفة من الفراغات والقيم الفارغة.
     *
     * @return array<int,string>
     */
    private static function stringList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            fn ($item) => trim((string) $item),
            is_array($value) ? $value : []
        )));
    }
}
