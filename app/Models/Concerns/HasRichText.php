<?php

namespace App\Models\Concerns;

use App\Support\RichHtml;
use App\Support\SummaryText;
use Illuminate\Database\Eloquent\Model;

/**
 * **نصٌّ يُحرَّر منسّقاً ويصل العميلَ بتنسيقه** (طلب المالك 2026-09-30) — ملخّص التذكرة وملخّص الاستشارة.
 *
 * لكلّ حقلٍ في `RICH_TEXT_FIELDS` عمودان: `{حقل}_html` المنسّق أصلٌ يحرّره المحامي والإدارة (منقّى بقائمة الملخّص
 * `RichHtml::cleanSummary` كتابةً وقراءةً)، و`{حقل}` النصّ العاديّ مشتقٌّ منه (`RichHtml::toPlain`) لما يقرأ نصّاً: سياق الذكاء،
 * واستخراج القرارات، والمعاينات، وسجلّ المراجعات، وقياس حجم التحرير.
 *
 * النموذج يعرّف `public const RICH_TEXT_FIELDS = [...]`.
 */
trait HasRichText
{
    public static function bootHasRichText(): void
    {
        // نصٌّ عاديّ كُتب بلا نسخته المنسّقة (توليد الذكاء، سحب Zoom، القالب، واجهةٌ ترسل نصّاً) — تسقط
        // المنسّقة القديمة فيُعرض النصّ الجديد لا ما سبقه
        static::saving(function (Model $model): void {
            foreach (static::RICH_TEXT_FIELDS as $field) {
                if ($model->isDirty($field) && ! $model->isDirty($field.'_html')) {
                    $model->setAttribute($field.'_html', null);
                }
            }
        });
    }

    /**
     * مدخلات التحرير إلى أعمدة: `{حقل}_html` يُنقّى ويُشتقّ منه نصّه، وإلّا فالنصّ العاديّ كما هو.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    public static function editableInput(array $input): array
    {
        $out = [];
        foreach (static::RICH_TEXT_FIELDS as $field) {
            if (array_key_exists($field.'_html', $input)) {
                $html = RichHtml::cleanSummary((string) $input[$field.'_html']);
                $plain = RichHtml::toPlain($html);
                $out[$field.'_html'] = $plain === '' ? null : $html;
                $out[$field] = $plain === '' ? null : $plain;
            } elseif (array_key_exists($field, $input)) {
                $out[$field] = $input[$field] === null ? null : (string) $input[$field];
            }
        }

        return $out;
    }

    /** النسخة المنسّقة المنقّاة لحقل — وإن لم تكن فنصّه العاديّ فقراتٍ وقوائم. */
    public function html(string $field): string
    {
        $stored = (string) $this->getAttribute($field.'_html');

        return trim($stored) !== ''
            ? RichHtml::cleanSummary($stored)
            : SummaryText::html((string) $this->getAttribute($field));
    }

    /**
     * **ما تغيّر فعلاً ممّا عُرض** — `editableInput` بعد إسقاط كلّ حقلٍ منسّقٍ عاد كما قدّمه الخادم (`html()`).
     *
     * الواجهة ترسل الحقول كلّها ولو لم يُلمس منها شيء؛ وكان الحقل المنسّق الأوّل «جديداً» دائماً (كان فارغاً)،
     * فيُعدّ تحريراً: يُجاز القالب الذي لم يحرّره أحد (حارس الصدق)، ويُسجَّل «عدّل» بدل «قَبِل» في حوكمة
     * الذكاء، ويُمسح من الاستشارة قراراتها بحفظٍ لم يغيّر شيئاً. غير المتغيّر الآن لا يُكتب ولا يُعدّ.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    public function changedInput(array $input): array
    {
        foreach (static::RICH_TEXT_FIELDS as $field) {
            if (array_key_exists($field.'_html', $input)
                && RichHtml::cleanSummary((string) $input[$field.'_html']) === RichHtml::cleanSummary($this->html($field))) {
                unset($input[$field.'_html']);
            }
        }

        return static::editableInput($input);
    }
}
