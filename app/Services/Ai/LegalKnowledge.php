<?php

namespace App\Services\Ai;

use App\Models\LegalSource;
use Illuminate\Support\Collection;

/**
 * الاسترجاع القانونيّ الموثَّق — الفلاتر **قبل** النموذج لا بعده.
 *
 * توجيه النموذج إلى «استخدم الأنظمة السعودية» لا يُنتج استشهاداً قابلاً للتدقيق؛
 * يُنتج نصّاً يبدو مرجعياً. هنا تُنتقى المقاطع من جدول معتمد بفلاتر إلزاميّة —
 * الاعتماد، والولاية، وسريان النصّ في تاريخ الواقعة، والمجال — ثم تُمرَّر للنموذج
 * بمعرّفاتها. فما يعود من النموذج يُطابَق بمعرّفٍ حقيقيّ، ولا يُصدَّق على كلمته.
 *
 * **الاسترجاع نصّيّ في هذه الدفعة** (كلمات مفتاحيّة + فلاتر). البحث الشعاعيّ
 * (embeddings) مؤجَّل عمداً: بناء استرجاع دلاليّ فوق قاعدة فارغة لا يمكن قياسه،
 * ويُرقّى حين يوجد محتوى معتمد تُقاس عليه جودة الاسترجاع.
 */
class LegalKnowledge
{
    /** سقف المقاطع المُمرَّرة للنموذج — تصغير السياق مبدأ لا تحسين. */
    public const MAX_PASSAGES = 6;

    /** أقصى طول لمقطع واحد يُمرَّر. */
    public const MAX_PASSAGE_CHARS = 1200;

    /**
     * مقاطع صالحة للاستشهاد في نزاعٍ بعينه.
     *
     * @param  string  $domain  مجال النزاع (تجاري/عمالي/تنفيذ…)
     * @param  string  $query  نصّ الموضوع لانتقاء الكلمات المفتاحيّة
     * @param  \DateTimeInterface|null  $asOf  تاريخ الواقعة — لا تاريخ اليوم: نصٌّ
     *                                         نُسخ بعد الواقعة لا يحكمها
     * @return Collection<int, LegalSource>
     */
    public static function retrieve(
        string $domain,
        string $query = '',
        ?\DateTimeInterface $asOf = null,
        string $jurisdiction = 'السعودية',
        int $limit = self::MAX_PASSAGES,
    ): Collection {
        $asOf ??= now();

        $builder = LegalSource::query()
            ->approved()
            ->effectiveAt($asOf)
            ->where('jurisdiction', $jurisdiction);

        if (trim($domain) !== '') {
            $builder->where(fn ($q) => $q->where('domain', $domain)->orWhereNull('domain'));
        }

        // الفلاتر القانونيّة (اعتماد/ولاية/سريان/مجال) **صارمة**، أما الكلمات المفتاحيّة
        // فترجيح لا شرط: اشتراط تطابقها جميعاً كان يُفرغ النتيجة في قاعدة صغيرة —
        // أي يُعطّل الاسترجاع عملياً بدل أن يضبطه. وإن لم تُرجّح شيئاً، تُعاد نتيجة
        // المجال كاملةً: مصدرٌ معتمد في المجال الصحيح خيرٌ من صمتٍ يدفع للاختلاق.
        $keywords = self::keywords($query);
        if ($keywords !== []) {
            $ranked = (clone $builder)
                ->where(function ($q) use ($keywords) {
                    foreach ($keywords as $word) {
                        $q->orWhere('text', 'like', "%{$word}%")
                            ->orWhere('title', 'like', "%{$word}%")
                            ->orWhere('system_name', 'like', "%{$word}%");
                    }
                })
                ->orderByDesc('effective_from')->limit($limit)->get();

            if ($ranked->isNotEmpty()) {
                return $ranked;
            }
        }

        return $builder->orderByDesc('effective_from')->limit($limit)->get();
    }

    /**
     * المقاطع بصيغة تُمرَّر للنموذج — كلٌّ بمعرّفه كي يُستشهد به لا بنصّه وحده.
     *
     * @param  Collection<int, LegalSource>  $sources
     */
    public static function asContext(Collection $sources): string
    {
        if ($sources->isEmpty()) {
            return '';
        }

        return $sources
            ->map(fn (LegalSource $s) => "[{$s->ref}] {$s->citation()}\n"
                .AiContextBuilder::clip((string) $s->text, self::MAX_PASSAGE_CHARS))
            ->implode("\n\n");
    }

    /**
     * هل يوجد سند كافٍ للاستشهاد أصلاً؟ حين لا يوجد، المخرج الصحيح
     * `insufficient_authority` — سؤالٌ للمراجع، لا مادّة متخيَّلة.
     */
    public static function hasSufficientAuthority(Collection $sources): bool
    {
        return $sources->isNotEmpty();
    }

    /**
     * معرّفات المصادر الحقيقيّة — يُطابَق بها ما يدّعيه النموذج.
     *
     * @param  array<int,string>  $refs
     * @return array<int,string> الموجود فعلاً منها
     */
    public static function existingRefs(array $refs): array
    {
        $refs = array_values(array_unique(array_filter(array_map('trim', $refs))));
        if ($refs === []) {
            return [];
        }

        return LegalSource::query()
            ->approved()
            ->whereIn('ref', $refs)
            ->pluck('ref')
            ->all();
    }

    /**
     * كلمات مفتاحيّة من نصّ الموضوع — تُسقَط القصيرة والشائعة كي لا يتحوّل
     * الترشيح إلى مطابقة كل شيء.
     *
     * @return array<int,string>
     */
    private static function keywords(string $query): array
    {
        $stop = ['على', 'في', 'من', 'إلى', 'عن', 'مع', 'هذا', 'هذه', 'التي', 'الذي', 'بين', 'بشأن', 'ضد'];

        return collect(preg_split('/[\s،.:؛()«»"]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn ($w) => trim($w))
            ->filter(fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, $stop, true))
            ->take(3) // ثلاث كلمات تكفي للترشيح؛ أكثر منها يُفرغ النتيجة
            ->values()
            ->all();
    }
}
