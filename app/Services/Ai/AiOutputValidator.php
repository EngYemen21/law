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

    /**
     * نتيجة فحص مستند.
     *
     * `related` **إلزاميّ ولا افتراض له**: غيابه يعني أن النموذج لم يحكم بالصلة، وحمله
     * على `false` يوسم مستنداً صحيحاً بأنه غير مرتبط، وحمله على `true` يُدخل مستنداً
     * أجنبياً إلى الملفّ. كلاهما قرارٌ لم يتّخذه أحد — فالصواب سقوط المخرج.
     *
     * @return array{related:bool,doc_type:string,summary:string,reason:string}|null
     */
    public static function documentAnalysis(?array $data): ?array
    {
        if (! is_array($data) || ! array_key_exists('related', $data) || ! is_bool($data['related'])) {
            return null;
        }

        return [
            'related' => $data['related'],
            'doc_type' => trim((string) ($data['doc_type'] ?? '')) ?: 'مستند',
            'summary' => (string) ($data['summary'] ?? ''),
            'reason' => (string) ($data['reason'] ?? ''),
        ];
    }

    /**
     * ملخّص ملفّ التذكرة للمحامي.
     *
     * `facts` و`key_points` تُقبل قائمةً أو نصّاً — النموذج يتأرجح بينهما، والتوحيد
     * هنا لا في كل موضع عرض.
     *
     * @return array{case_summary:string,attachments_summary:string,facts:string,key_points:string}|null
     */
    public static function ticketSummary(?array $data): ?array
    {
        if (! is_array($data) || trim((string) ($data['case_summary'] ?? '')) === '') {
            return null;
        }

        return [
            'case_summary' => (string) $data['case_summary'],
            'attachments_summary' => (string) ($data['attachments_summary'] ?? ''),
            'facts' => self::bulletedText($data['facts'] ?? null),
            'key_points' => self::bulletedText($data['key_points'] ?? null),
        ];
    }

    /**
     * القرارات المستخرَجة من محضر أو تفريغ.
     *
     * **مصفوفة فارغة نتيجةٌ صحيحة لا فشل**: نصٌّ بلا قرارات يجب أن يُخرج صفراً، لا أن
     * يُختلق منه قرار. لذا يُفرَّق هنا بين «المفتاح غائب» (بنية خاطئة ⇒ `null`)
     * و«المفتاح موجود وفارغ» (لا قرارات ⇒ قائمة فارغة).
     *
     * @return array{decisions:array<int,string>}|null
     */
    public static function decisions(?array $data): ?array
    {
        if (! is_array($data) || ! array_key_exists('decisions', $data) || ! is_array($data['decisions'])) {
            return null;
        }

        return ['decisions' => self::stringList($data['decisions'])];
    }

    /** قيمة من قائمة مسموحة، وإلّا البديل المعلن (لا تخمين). */
    private static function oneOf(mixed $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? (string) $value : $default;
    }

    /** نصّ نقاطٍ موحَّد سواء عاد النموذج بقائمة أو بنصٍّ واحد. */
    private static function bulletedText(mixed $value): string
    {
        if (! is_array($value)) {
            return (string) $value;
        }

        return implode("\n", array_map(fn ($item) => '• '.ltrim(trim((string) $item), '• '), $value));
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
