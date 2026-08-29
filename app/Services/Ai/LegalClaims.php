<?php

namespace App\Services\Ai;

/**
 * عقد المخرج القانونيّ المُستشهِد: كل ادّعاء نظاميّ مربوطٌ بمصدرٍ **يُتحقَّق من
 * وجوده خادمياً**، وما لم يُربط يُوسَم غير مدعوم بدل أن يمرّ كأنه مسنَد.
 *
 * الشكل المطلوب من النموذج:
 * {
 *   "claims": [{"text": "...", "source_id": "LS-1", "source_excerpt": "...", "support": "supported"}],
 *   "unsupported_claims": ["..."],
 *   "draft": "..."
 * }
 *
 * **القاعدة الحاكمة:** النموذج لا يُصدَّق في رقم مادّة. `source_id` يُطابَق بصفٍّ
 * معتمد في `legal_sources`؛ فإن لم يوجد سقط الادّعاء إلى `unsupported_claims`
 * مهما بدا مقنعاً. هذا ما يفصل استشهاداً قابلاً للتدقيق عن نصٍّ يبدو مرجعياً.
 */
class LegalClaims
{
    public const SUPPORTED = 'supported';

    public const UNSUPPORTED = 'unsupported';

    /** لا سند كافٍ في قاعدة المعرفة — سؤالٌ للمراجع لا مادّة متخيَّلة. */
    public const INSUFFICIENT_AUTHORITY = 'insufficient_authority';

    /**
     * تحقّق خادميّ مستقلّ من مخرج الصياغة.
     *
     * @param  array<int,string>  $existingRefs  معرّفات المصادر الحقيقيّة (من LegalKnowledge)
     * @return array{draft:string,claims:array<int,array{text:string,source_id:string,source_excerpt:string,support:string}>,unsupported_claims:array<int,string>,verdict:string}|null
     */
    public static function validate(?array $data, array $existingRefs): ?array
    {
        if (! is_array($data) || trim((string) ($data['draft'] ?? '')) === '') {
            return null;
        }

        $claims = [];
        $unsupported = array_values(array_filter(array_map(
            fn ($c) => trim((string) $c),
            (array) ($data['unsupported_claims'] ?? [])
        )));

        foreach ((array) ($data['claims'] ?? []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $text = trim((string) ($raw['text'] ?? ''));
            $sourceId = trim((string) ($raw['source_id'] ?? ''));
            if ($text === '') {
                continue;
            }

            // ⚠️ الحكم للخادم لا للنموذج: معرّف لا يقابله صفٌّ معتمد ⇒ ادّعاء غير مدعوم
            if ($sourceId === '' || ! in_array($sourceId, $existingRefs, true)) {
                $unsupported[] = $text;

                continue;
            }

            $claims[] = [
                'text' => $text,
                'source_id' => $sourceId,
                'source_excerpt' => trim((string) ($raw['source_excerpt'] ?? '')),
                'support' => self::SUPPORTED,
            ];
        }

        return [
            'draft' => (string) $data['draft'],
            'claims' => $claims,
            'unsupported_claims' => array_values(array_unique($unsupported)),
            'verdict' => self::verdict($claims, $unsupported),
        ];
    }

    /**
     * مخرج «لا سند كافٍ» — يُستعمل حين تعود قاعدة المعرفة فارغة، بدل تشغيل
     * النموذج ليؤلّف مواد من ذاكرته.
     *
     * @return array{draft:string,claims:array<int,mixed>,unsupported_claims:array<int,mixed>,verdict:string}
     */
    public static function insufficientAuthority(): array
    {
        return [
            'draft' => '',
            'claims' => [],
            'unsupported_claims' => [],
            'verdict' => self::INSUFFICIENT_AUTHORITY,
        ];
    }

    /** هل المخرج صالح للعرض على المحامي كمسودّة مستنَدة؟ */
    public static function isCitable(array $result): bool
    {
        return ($result['verdict'] ?? '') === self::SUPPORTED && $result['claims'] !== [];
    }

    /**
     * @param  array<int,array<string,string>>  $claims
     * @param  array<int,string>  $unsupported
     */
    private static function verdict(array $claims, array $unsupported): string
    {
        if ($claims === []) {
            // لا ادّعاء مسنَد واحد: المخرج لا يصلح للاستشهاد مهما طال نصّه
            return $unsupported === [] ? self::INSUFFICIENT_AUTHORITY : self::UNSUPPORTED;
        }

        return $unsupported === [] ? self::SUPPORTED : self::UNSUPPORTED;
    }
}
