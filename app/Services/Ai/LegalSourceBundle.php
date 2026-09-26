<?php

namespace App\Services\Ai;

use App\Support\LegalCatalogue;

/**
 * ملفّ مصادر قانونيّة واحد من `database/legal-sources/` — قراءته والتحقّق منه **قبل أيّ كتابة**.
 *
 * **لماذا ملفّات في المستودع لا صفوفٌ في قاعدة التطوير وحدها؟** كانت المواد تُدخَل يدوياً في
 * قاعدة التطوير فقط، فالنشر على خادمٍ جديد يبدأ بجدولٍ **فارغ**: المساعد القانونيّ يعمل بلا سند
 * ولا خطأ يُرى. الملفّ المُصدَّر مع الشيفرة يجعل المحتوى جزءاً من الإصدار نفسه.
 *
 * صيغتان مقبولتان:
 *   - مصفوفة صفوف (الصيغة القديمة لـ`ai:import-sources`).
 *   - كائن `{bundle, reviewed, sources}`: `bundle` بيانات وصفيّة للمراجع (المرسوم، الرابط الأصليّ،
 *     بصمة الصفحة المنزَّلة) لا تُكتب في الجدول، و`reviewed` شهادة اعتماد سابق (انظر `vouches`).
 *
 * التحقّق هنا هو **حارس النشر**: ملفٌّ معطوب يُسقط `ai:sync-sources` بخطأ صريح قبل أن يلمس
 * صفّاً واحداً، بدل أن يدخل نصٌّ مجهول المالك أو مجالٌ لا يطابقه الاسترجاع فيصمت.
 */
final class LegalSourceBundle
{
    /** بلا هذه الحقول لا يكون النصّ مصدراً — هو نصّ مجهول. */
    public const REQUIRED = ['ref', 'system_name', 'text', 'source_owner', 'effective_from'];

    /** أعمدة `string` في الجدول (varchar 255): تجاوزها يُسقط الإدراج على MySQL لا على SQLite الاختبار. */
    private const VARCHAR = ['ref', 'system_name', 'article_no', 'title', 'jurisdiction', 'domain', 'version', 'source_owner', 'source_url', 'usage_scope'];

    /** الحقول التي يحملها الصفّ إلى الجدول — وما عداها في الملفّ وصفٌ لا بيانات. */
    public const FIELDS = ['ref', 'system_name', 'article_no', 'title', 'text', 'jurisdiction', 'domain', 'version',
        'effective_from', 'effective_to', 'source_owner', 'source_url', 'usage_scope'];

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{by: string, at: string}|null  $reviewed
     */
    private function __construct(
        public readonly string $path,
        public readonly array $rows,
        public readonly ?array $reviewed,
        public readonly array $errors,
    ) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            return new self($path, [], null, ["الملفّ غير موجود: {$path}"]);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return new self($path, [], null, ['ليس JSON صالحاً: '.json_last_error_msg()]);
        }

        $isList = array_is_list($decoded);
        $rows = $isList ? $decoded : ($decoded['sources'] ?? null);
        if (! is_array($rows) || ! array_is_list($rows)) {
            return new self($path, [], null, ['يُتوقَّع مصفوفة صفوف، أو كائن فيه «sources» مصفوفةً.']);
        }

        $reviewed = $isList ? null : ($decoded['reviewed'] ?? null);
        $errors = [];

        if ($reviewed !== null && (! is_array($reviewed) || trim((string) ($reviewed['by'] ?? '')) === '' || ! self::isDate($reviewed['at'] ?? null))) {
            $errors[] = '«reviewed» يلزمه «by» (من اعتمد) و«at» (تاريخ YYYY-MM-DD).';
            $reviewed = null;
        }

        return new self($path, $rows, $reviewed, [...$errors, ...self::validateRows($rows, $reviewed !== null)]);
    }

    public function name(): string
    {
        return basename($this->path);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * **شهادة الاعتماد السابق — لما اعتُمد فعلاً وحده.**
     *
     * الأصل أنّ ما يدخل من الطرفيّة «مسودة»: الاعتماد فعلٌ بشريّ في النظام. لكنّ نصّاً راجعه محامٍ
     * واعتمده في بيئةٍ ثمّ صُدِّر حرفياً لا يُعاد تحويله مسودةً في كلّ خادمٍ جديد — ذلك يُطفئ المساعد
     * القانونيّ بعد كلّ نشر حتى يُعاد اعتماد ٧٨٦ مادّة. فالشهادة تُقبل بشرطين معاً: كتلة `reviewed`
     * على مستوى الملفّ (من ومتى)، و`approved: true` على الصفّ نفسه — وتصدير التطوير لا يكتبها
     * إلّا لصفٍّ حالته «معتمد» هناك. نظامٌ جديد يُضاف بلا شهادة فيدخل مسودةً حتماً.
     *
     * @param  array<string, mixed>  $row
     */
    public function vouches(array $row): bool
    {
        return $this->reviewed !== null && ($row['approved'] ?? false) === true;
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<string>
     */
    private static function validateRows(array $rows, bool $hasReview): array
    {
        $errors = [];
        $seen = [];

        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                $errors[] = "[{$i}] الصفّ ليس كائناً.";

                continue;
            }
            $at = '['.$i.(is_string($row['ref'] ?? null) ? ' '.$row['ref'] : '').']';

            $missing = array_values(array_filter(self::REQUIRED, fn (string $f) => trim((string) ($row[$f] ?? '')) === ''));
            if ($missing !== []) {
                $errors[] = "{$at} حقول حاكمة ناقصة: ".implode('، ', $missing);

                continue;
            }

            $ref = (string) $row['ref'];
            if (! preg_match('/^LS-[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $ref)) {
                $errors[] = "{$at} معرّف غير صالح (LS-…بحروف لاتينيّة كبيرة وأرقام).";
            }
            if (isset($seen[$ref])) {
                $errors[] = "{$at} معرّف مكرّر في الملفّ نفسه.";
            }
            $seen[$ref] = true;

            foreach (self::VARCHAR as $f) {
                if (isset($row[$f]) && ! is_string($row[$f])) {
                    $errors[] = "{$at} «{$f}» يجب أن يكون نصّاً.";
                } elseif (isset($row[$f]) && mb_strlen($row[$f]) > 255) {
                    $errors[] = "{$at} «{$f}» أطول من 255 حرفاً.";
                }
            }

            $text = (string) $row['text'];
            // بقايا HTML تعني أنّ التحويل من الصفحة لم يكتمل — والنموذج سيقرأها نصّاً نظامياً
            if (preg_match('/<\/?[a-z][a-z0-9]*[^>]*>|&(?:[a-z]+|#\d+|#x[0-9a-f]+);/i', $text)) {
                $errors[] = "{$at} النصّ يحوي بقايا HTML.";
            }

            if (! self::isDate($row['effective_from'])) {
                $errors[] = "{$at} «effective_from» ليس تاريخاً (YYYY-MM-DD).";
            } elseif (($row['effective_to'] ?? null) !== null) {
                if (! self::isDate($row['effective_to'])) {
                    $errors[] = "{$at} «effective_to» ليس تاريخاً (YYYY-MM-DD).";
                } elseif ($row['effective_to'] < $row['effective_from']) {
                    $errors[] = "{$at} ينتهي سريانه قبل أن يبدأ.";
                }
            }

            $domain = $row['domain'] ?? null;
            if ($domain !== null && ! self::isRetrievableDomain((string) $domain)) {
                $errors[] = "{$at} المجال «{$domain}» ليس اسم قسمٍ ولا اسماً بديلاً له في كتالوج الأقسام — لن يسترجعه LegalKnowledge.";
            }

            if (array_key_exists('approved', $row) && ! is_bool($row['approved'])) {
                $errors[] = "{$at} «approved» قيمة منطقيّة (true/false).";
            } elseif (($row['approved'] ?? false) === true && ! $hasReview) {
                $errors[] = "{$at} «approved: true» بلا كتلة «reviewed» على مستوى الملفّ.";
            }
        }

        return $errors;
    }

    /**
     * المجال يُطابَق في الاسترجاع بـ`whereIn('domain', namesFor(قسم))` — فالمقبول اسمُ قسمٍ أو اسمٌ
     * بديل له **حرفياً**. اسمُ خدمةٍ يدلّ على القسم لكنّه ليس بين أسمائه، فمصدرٌ مجاله كذلك لا يُسترجع أبداً.
     */
    public static function isRetrievableDomain(string $domain): bool
    {
        $department = LegalCatalogue::resolveDepartment($domain);

        return $department !== null && in_array($domain, LegalCatalogue::namesFor($department), true);
    }

    private static function isDate(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y);
    }
}
