<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * تنقية HTML المحرّر القانونيّ (TipTap) بقائمة سماحٍ — ما لا تُنتجه أدوات المحرّر لا يُحفظ ولا يُعرض.
 *
 * **لماذا في الخادم:** `content_html` يُدرَج كما هو في صفحة الطباعة (`dangerouslySetInnerHTML`) وفي
 * صفحة PDF التي يصيّرها كروم؛ فوسم `<script>` أو `<iframe>` أو سمة `on*` يكتبها صاحب المستند
 * تُنفَّذ في متصفّح المدير الذي يفتحه، أو في كروم الخادم فتقرأ ملفّاته وتطلب عناوينه الداخليّة.
 * الواجهة لا تحرس نفسها — الطلب المباشر يتجاوزها — فالحدّ هنا.
 *
 * - وسومٌ خطرة (سكربت، إطار، نموذج، SVG…) تُحذف بمحتواها.
 * - وسومٌ مجهولة غير خطرة تُفَكّ: يبقى نصّها ويسقط غلافها.
 * - السمات بقائمة سماح؛ `href`/`src` بمخطّطات آمنة؛ و`style` بلا `url()` ولا `expression`.
 */
final class RichHtml
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'div', 'span',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins', 'mark', 'sub', 'sup', 'small',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'colgroup', 'col', 'caption',
        'a', 'img', 'figure', 'figcaption',
    ];

    /**
     * **قائمة الملخّص الذي يصل العميل** (ملخّص التذكرة والاستشارة — `HasRichText`) — على قدر أدوات محرّره فقط.
     * قائمة المحرّر القانونيّ أوسع (صور، روابط، جداول، أصناف، `style` بلا قيد موضع)، ونصٌّ يُرسَل بطلبٍ مباشر
     * كان يمرّ بها إلى صفحة العميل: طبقةٌ تغطّي الشاشة (`position:fixed` وأصناف التصميم)، وصورةٌ خارجيّة تتبّعه،
     * ورابطٌ خارجيّ. هنا: وسوم التنسيق وحدها، و`style` لونٌ ومحاذاةٌ فقط؛ وما سواها يُفَكّ (يبقى نصّه).
     */
    private const SUMMARY_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h3', 'h4', 'ul', 'ol', 'li', 'span'];

    /** تُحذف بمحتواها — لا يُفَكّ غلافها فيبقى نصّ سكربتٍ ظاهراً. */
    private const DROPPED_TAGS = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'form', 'input', 'button', 'textarea', 'select', 'option', 'link', 'meta', 'base',
        'svg', 'math', 'template', 'noscript', 'audio', 'video', 'source', 'track', 'canvas',
        'portal', 'title', 'head',
    ];

    private const ALLOWED_ATTRS = [
        'style', 'class', 'dir', 'align', 'title', 'lang',
        'colspan', 'rowspan', 'colwidth', 'width', 'height',
        'href', 'target', 'rel', 'src', 'alt', 'start', 'type',
    ];

    /**
     * تحويل محتوى HTML إلى نص قضائي منظم يحافظ على فواصل الأسطر والفقرات
     * بدلاً من `strip_tags` البحت الذي يدمج الفقرات والكلمات ببعضها. (نُقل من `DocumentEditorController`
     * ليقرأه ملخّص التذكرة المنسّق أيضاً: نصّه العاديّ مشتقٌّ من نسخته المنسّقة.)
     */
    public static function toPlain(string $html): string
    {
        // 1. استبدال فواصل الأسطر الصريحة
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html);

        // 2. تحويل نهايات وسوم الكتل (الفقرات والعناوين والصفوف) إلى أسطر جديدة
        $text = preg_replace('/<\/(p|div|h[1-6]|tr|blockquote|li)>/i', "\n\n", (string) $text);

        // 3. تحويل عناصر القوائم إلى علامات نقطية
        $text = preg_replace('/<li[^>]*>/i', '• ', (string) $text);

        // 4. فك تشفير الكيانات وتجريد بقية وسوم HTML
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);

        // 5. ضبط الفراغات وتوحيد الأسطر الزائدة (أقصى فراغ سطران فارغان)
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $text = preg_replace("/[ \t]+/", ' ', (string) $text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);

        return trim((string) $text);
    }

    public static function clean(?string $html): string
    {
        return self::sanitize((string) $html, summary: false);
    }

    /** تنقية الملخّص الذي يصل العميل — القائمة الضيّقة (`SUMMARY_TAGS`، لونٌ ومحاذاةٌ فقط). */
    public static function cleanSummary(?string $html): string
    {
        return self::sanitize((string) $html, summary: true);
    }

    private static function sanitize(string $html, bool $summary): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__rich_root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = (new DOMXPath($doc))->query('//div[@id="__rich_root"]')->item(0);
        if (! $root instanceof DOMElement) {
            return e(strip_tags($html));
        }

        self::walk($root, $summary);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function walk(DOMNode $node, bool $summary): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROPPED_TAGS, true)) {
                $node->removeChild($child);

                continue;
            }

            self::walk($child, $summary);

            if (! in_array($tag, $summary ? self::SUMMARY_TAGS : self::ALLOWED_TAGS, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            if ($summary) {
                self::summaryAttributes($child);
            } else {
                self::cleanAttributes($child, $tag);
            }
        }
    }

    /** في الملخّص: `style` وحده، ومنه اللون (سداسيّ أو rgb) والمحاذاة فقط — يُعاد بناؤه لا يُقبل كما هو. */
    private static function summaryAttributes(DOMElement $el): void
    {
        $style = '';
        foreach (explode(';', $el->getAttribute('style')) as $decl) {
            [$prop, $value] = array_map('trim', explode(':', $decl, 2) + [1 => '']);
            $prop = strtolower($prop);
            if ($prop === 'color' && preg_match('/^(#[0-9a-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*[0-9.]+\s*)?\))$/i', $value)) {
                $style .= "color: {$value}; ";
            } elseif ($prop === 'text-align' && in_array(strtolower($value), ['right', 'left', 'center', 'justify', 'start', 'end'], true)) {
                $style .= 'text-align: '.strtolower($value).'; ';
            }
        }

        foreach (iterator_to_array($el->attributes) as $attr) {
            $el->removeAttribute($attr->name);
        }
        if ($style !== '') {
            $el->setAttribute('style', rtrim($style));
        }
    }

    private static function cleanAttributes(DOMElement $el, string $tag): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $value = trim($attr->value);

            $keep = match (true) {
                str_starts_with($name, 'data-') => ! str_contains(strtolower($value), 'javascript:'),
                ! in_array($name, self::ALLOWED_ATTRS, true) => false,
                $name === 'href' => $tag === 'a' && self::safeHref($value),
                $name === 'src' => $tag === 'img' && self::safeImageSrc($value),
                $name === 'style' => self::safeStyle($value),
                default => true,
            };

            if (! $keep) {
                $el->removeAttribute($attr->name);
            }
        }

        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function safeHref(string $value): bool
    {
        return (bool) preg_match('~^(https?://|mailto:|tel:|#|/(?!/))~i', $value);
    }

    /** صور المحرّر تُرفع مضمَّنةً (`data:image/*`) أو برابط https؛ لا `data:image/svg` (قد يحمل سكربتاً). */
    private static function safeImageSrc(string $value): bool
    {
        return (bool) preg_match('~^(data:image/(png|jpe?g|gif|webp);base64,|https://)~i', $value);
    }

    private static function safeStyle(string $value): bool
    {
        return ! preg_match('~url\s*\(|expression\s*\(|javascript:|@import|behavior\s*:|-moz-binding~i', $value);
    }
}
