<?php

namespace App\Support;

use App\Models\LegalDocument;
use DOMDocument;
use DOMElement;
use DOMNode;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

/**
 * **ملفّ Word حقيقيّ (‎.docx) للمستند القانونيّ** (قرار المالك 2026-09-30، الخيار «ب»).
 *
 * كان «Word» صفحة HTML باسم ‎.doc تُبنى في المتصفّح: بلا اتّجاهٍ من اليمين (فتنقلب الأقواس وعلامات التنصيص)، وشعارٌ
 * بحجمه الكامل (Word يتجاهل `max-height`)، وتاريخٌ وتذييلٌ غير ما في PDF، ويأخذ ما على الشاشة لا المحفوظ. الآن يُبنى
 * في الخادم من **`content_html` نفسه الذي يصيّره PDF**، وبرأس المستند وتذييله من `LegalDocMeta`، وقيم التنسيق من
 * `LegalDocStyle` (نظير `legal-document.css` الذي يقرؤه المحرّر وPDF).
 *
 * ما يدعمه المحرّر يُنقل: العناوين، والعريض والمائل والتسطير والشطب، واللون والتظليل والحجم والخطّ وتباعد الأسطر،
 * والمحاذاة، والقوائم المرقّمة والنقطيّة (متداخلةً)، والاقتباس، والجداول (بدمج الأعمدة)، والفاصل، والصور، والروابط.
 * كلّ فقرةٍ وجدولٍ من اليمين (`bidi` · `bidiVisual`)، والنصّ العربيّ `rtl` بخطّه وحجمه للنصوص المركّبة (`cs` · `szCs`).
 */
final class LegalDocx
{
    /**
     * الخطوط التي تُضمَّن في الملفّ (`resources/fonts/legal`، رخصة OFL تسمح بالتضمين): بدونها يستبدل Word ‏Tajawal بخطٍّ
     * آخر على جهازٍ لم تُثبَّت عليه — فلا يطابق PDF. التضمين كما يفعله Word نفسه («حفظ الخطوط في الملفّ»).
     */
    private const EMBEDDABLE = ['Tajawal', 'Amiri'];

    /** عرض المحتوى: A4 ‏(210mm) ناقص الهامشين — كعرض المحرّر وPDF. */
    private const CONTENT_MM = 210 - 14 - 14;

    private PhpWord $word;

    /** @var array<string, true> الخطوط المستعمَلة فعلاً — يُضمَّن منها ما له ملفّ (`EMBEDDABLE`) */
    private array $usedFonts = [];

    /** @var list<string> صورٌ مفكوكة من `data:` — تُحذف بعد الحفظ (PhpWord يقرؤها عنده) */
    private array $temps = [];

    public static function build(LegalDocument $doc): string
    {
        return (new self)->render($doc);
    }

    private function render(LegalDocument $doc): string
    {
        $m = LegalDocMeta::of($doc);

        $this->word = new PhpWord;
        $this->word->setDefaultFontName(LegalDocStyle::FONT);
        $this->word->setDefaultFontSize(LegalDocStyle::BODY_PT);
        $this->word->getSettings()->setThemeFontLang(new Language(Language::EN_US, null, 'ar-SA'));
        $this->word->getDocInfo()->setTitle($m['title'])->setCreator($m['author']);
        $this->defineLists();

        [$top, $right, $bottom, $left] = LegalDocStyle::PAGE_MARGINS_MM;
        $section = $this->word->addSection([
            'paperSize' => 'A4',
            'marginTop' => (int) Converter::cmToTwip($top / 10),
            'marginRight' => (int) Converter::cmToTwip($right / 10),
            'marginBottom' => (int) Converter::cmToTwip($bottom / 10),
            'marginLeft' => (int) Converter::cmToTwip($left / 10),
        ]);

        if ($m['showHeader']) {
            $this->header($section, $m);
        }

        $section->addText($this->clean($m['title']), $this->font(['size' => LegalDocStyle::TITLE_PT, 'bold' => true, 'color' => LegalDocStyle::TITLE_COLOR], $m['title']), [
            'bidi' => true, 'alignment' => Jc::CENTER, 'spaceAfter' => 360,
            'borderBottomSize' => 8, 'borderBottomColor' => 'edf2f6',
        ]);

        $this->blocks($this->parse((string) $doc->content_html), $section, 0);

        $this->closing($section, $m);

        $path = self::tempFile('docx');
        IOFactory::createWriter($this->word, 'Word2007')->save($path);
        $this->embedFonts($path);
        $binary = (string) file_get_contents($path);
        foreach ([$path, ...$this->temps] as $file) {
            @unlink($file);
        }

        return $binary;
    }

    // ── رأس المستند وتذييله — من `LegalDocMeta` كما في PDF ──

    /** @param array<string, mixed> $m */
    private function header(AbstractContainer $section, array $m): void
    {
        $table = $section->addTable(['bidiVisual' => true, 'width' => 5000, 'unit' => 'pct', 'borderBottomSize' => 20, 'borderBottomColor' => '0e5c9c', 'cellMargin' => 40]);
        $table->addRow();
        $third = (int) Converter::cmToTwip(self::CONTENT_MM / 10 / 4);

        $logo = $table->addCell($third, ['valign' => 'center']);
        if ($m['logoPath'] !== null && ! str_ends_with((string) $m['logoPath'], '.svg')) {
            // حجمٌ ثابت بنسبة الصورة — كان Word يتجاهل `max-height` فيعرض الشعار بحجمه الكامل (1669×511)
            [$w, $h] = @getimagesize((string) $m['logoPath']) ?: [300, 100];
            $width = 105;
            $logo->addImage((string) $m['logoPath'], ['width' => $width, 'height' => (int) round($width * $h / max(1, $w)), 'alignment' => Jc::START]);
        }

        $center = $table->addCell($third * 2, ['valign' => 'center']);
        $center->addText($this->clean($m['officeName']), $this->font(['size' => 13, 'bold' => true, 'color' => '0a2a55'], $m['officeName']), ['bidi' => true, 'alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        if ($m['licenseNo'] !== '') {
            $line = 'ترخيص رقم: '.$m['licenseNo'];
            $center->addText($this->clean($line), $this->font(['size' => 8.5, 'color' => '607689'], $line), ['bidi' => true, 'alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }

        $contact = $table->addCell($third, ['valign' => 'center']);
        foreach (array_filter([$m['phone'], $m['email'], $m['address']]) as $line) {
            $contact->addText($this->clean($line), $this->font(['size' => 8, 'color' => '607689'], $line), ['bidi' => true, 'alignment' => Jc::END, 'spaceAfter' => 0]);
        }

        $section->addText('', [], ['spaceAfter' => 200]);
    }

    /** @param array<string, mixed> $m */
    private function closing(AbstractContainer $section, array $m): void
    {
        $section->addText('', [], ['spaceAfter' => 400, 'borderBottomSize' => 4, 'borderBottomColor' => 'e1e8ee']);

        $table = $section->addTable(['bidiVisual' => true, 'width' => 5000, 'unit' => 'pct']);
        $table->addRow();
        $col = (int) Converter::cmToTwip(self::CONTENT_MM / 10 / 3);

        $author = $table->addCell($col);
        $author->addText('حرر بواسطة:', $this->font(['size' => 8.5, 'color' => '607689'], 'ح'), ['bidi' => true, 'spaceAfter' => 0]);
        $author->addText($this->clean($m['author']), $this->font(['size' => 10, 'bold' => true], $m['author']), ['bidi' => true, 'spaceAfter' => 0]);
        $date = 'التاريخ: '.$m['date'];
        $author->addText($this->clean($date), $this->font(['size' => 8.5, 'color' => '607689'], $date), ['bidi' => true, 'spaceBefore' => 40, 'spaceAfter' => 0]);

        $approved = $table->addCell($col);
        if ($m['approved'] !== null) {
            $approved->addText('✓ معتمد رسمياً من الإدارة', $this->font(['size' => 9, 'bold' => true, 'color' => '1e9d6b'], 'م'), ['bidi' => true, 'alignment' => Jc::CENTER, 'spaceAfter' => 0]);
            $line = 'المعتمد: '.$m['approved']['by'].($m['approved']['at'] !== '' ? ' — '.$m['approved']['at'] : '');
            $approved->addText($this->clean($line), $this->font(['size' => 8, 'color' => '1e9d6b'], $line), ['bidi' => true, 'alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }

        $sign = $table->addCell($col);
        $sign->addText('التوقيع والختم', $this->font(['size' => 8.5, 'color' => '607689'], 'ت'), ['bidi' => true, 'alignment' => Jc::END, 'spaceAfter' => 360]);
        $sign->addText('', [], ['alignment' => Jc::END, 'borderBottomSize' => 4, 'borderBottomColor' => '90a2b2', 'spaceAfter' => 0]);
    }

    // ── المحتوى: HTML المحرّر (TipTap) عنصراً عنصراً ──

    private function parse(string $html): DOMElement
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body><div id="root">'.$html.'</div></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom->getElementById('root') ?? $dom->createElement('div');
    }

    /** عناصر الكتل داخل حاوية (القسم أو خليّة الجدول). */
    private function blocks(DOMNode $parent, AbstractContainer $container, int $listDepth): void
    {
        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                if (trim((string) $node->textContent) !== '') {
                    $this->inline($node, $container->addTextRun($this->paragraph(null)), $this->baseFont());
                }

                continue;
            }

            $tag = strtolower($node->tagName);
            $style = $this->styles($node);

            match (true) {
                $tag === 'p' => $this->inlineChildren($node, $container->addTextRun($this->paragraph($node)), $this->fontFrom($style, $this->baseFont())),
                in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) => $this->heading($node, $container, (int) substr($tag, 1)),
                $tag === 'ul' || $tag === 'ol' => $this->listItems($node, $container, $tag === 'ol' ? 'legal-decimal' : 'legal-bullet', $listDepth),
                $tag === 'blockquote' => $this->quote($node, $container),
                $tag === 'table' => $this->table($node, $container),
                $tag === 'hr' => $container->addText('', [], ['borderBottomSize' => 9, 'borderBottomColor' => LegalDocStyle::TABLE_BORDER, 'spaceAfter' => 240]),
                $tag === 'img' => $this->image($node, $container),
                default => $this->blocks($node, $container, $listDepth),
            };
        }
    }

    private function heading(DOMElement $node, AbstractContainer $container, int $level): void
    {
        [$size, $color] = LegalDocStyle::HEADINGS[min(4, $level)];
        $run = $container->addTextRun($this->paragraph($node, [
            'keepNext' => true,
            'spaceBefore' => (int) round($size * 20),
            'spaceAfter' => (int) round($size * 0.45 * 20),
            'lineHeight' => 1.5,
        ]));
        $this->inlineChildren($node, $run, ['size' => $size, 'color' => $color, 'bold' => true] + $this->baseFont());
    }

    private function listItems(DOMElement $list, AbstractContainer $container, string $numbering, int $depth): void
    {
        foreach ($list->childNodes as $li) {
            if (! $li instanceof DOMElement || strtolower($li->tagName) !== 'li') {
                continue;
            }

            $run = $container->addListItemRun($depth, $numbering, $this->paragraph(null, ['spaceAfter' => 60]));
            $first = true;
            foreach ($li->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['ul', 'ol'], true)) {
                    $this->listItems($child, $container, strtolower($child->tagName) === 'ol' ? 'legal-decimal' : 'legal-bullet', $depth + 1);

                    continue;
                }
                if (! $first) {
                    $run->addTextBreak();
                }
                $this->inline($child, $run, $this->baseFont());
                $first = false;
            }
        }
    }

    private function quote(DOMElement $node, AbstractContainer $container): void
    {
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement && trim((string) $child->textContent) === '') {
                continue;
            }
            $run = $container->addTextRun($this->paragraph($child instanceof DOMElement ? $child : null, [
                'shading' => ['fill' => LegalDocStyle::QUOTE_BG],
                'borderRightSize' => 24, 'borderRightColor' => LegalDocStyle::QUOTE_BORDER, 'borderRightSpace' => 8,
                'indentation' => ['left' => 200, 'right' => 200],
            ]));
            $child instanceof DOMElement ? $this->inlineChildren($child, $run, $this->baseFont()) : $this->inline($child, $run, $this->baseFont());
        }
    }

    private function table(DOMElement $node, AbstractContainer $container): void
    {
        $rows = [];
        foreach ($node->getElementsByTagName('tr') as $tr) {
            $rows[] = $tr;
        }
        if ($rows === []) {
            return;
        }

        $columns = 0;
        foreach ($rows as $tr) {
            $n = 0;
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $n += max(1, (int) $cell->getAttribute('colspan'));
                }
            }
            $columns = max($columns, $n);
        }
        $unit = (int) (Converter::cmToTwip(self::CONTENT_MM / 10) / max(1, $columns));

        $table = $container->addTable([
            'bidiVisual' => true, 'width' => 5000, 'unit' => 'pct', 'layout' => 'fixed',
            'borderSize' => 6, 'borderColor' => LegalDocStyle::TABLE_BORDER, 'cellMargin' => 110,
        ]);
        // الجدول كتلةٌ واحدة كما `break-inside: avoid` في PDF: لا ينقسم صفّ، ويلتصق كلّ صفٍّ بتاليه (keepNext)
        $last = count($rows) - 1;
        foreach ($rows as $i => $tr) {
            $table->addRow(null, ['cantSplit' => true]);
            foreach ($tr->childNodes as $cellNode) {
                if (! $cellNode instanceof DOMElement || ! in_array(strtolower($cellNode->tagName), ['td', 'th'], true)) {
                    continue;
                }
                $span = max(1, (int) $cellNode->getAttribute('colspan'));
                $head = strtolower($cellNode->tagName) === 'th';
                $cell = $table->addCell($unit * $span, array_filter([
                    'gridSpan' => $span > 1 ? $span : null,
                    'bgColor' => $head ? LegalDocStyle::TABLE_HEAD_BG : null,
                    'valign' => 'top',
                ]));
                $this->cellContent($cellNode, $cell, $head, $i < $last);
            }
        }
        $container->addText('', [], ['spaceAfter' => 120]);
    }

    private function cellContent(DOMElement $node, Cell $cell, bool $head, bool $keepNext): void
    {
        $hasBlock = false;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['p', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'table', 'blockquote'], true)) {
                $hasBlock = true;
            }
        }

        $base = $head ? ['bold' => true, 'color' => '0a2a55'] + $this->baseFont() : $this->baseFont();
        if (! $hasBlock) {
            $this->inlineChildren($node, $cell->addTextRun($this->paragraph(null, ['spaceAfter' => 0, 'keepNext' => $keepNext])), $base);

            return;
        }

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'p') {
                $this->inlineChildren($child, $cell->addTextRun($this->paragraph($child, ['spaceAfter' => 0, 'keepNext' => $keepNext])), $this->fontFrom($this->styles($child), $base));
            } elseif ($child instanceof DOMElement) {
                $wrapper = $child->ownerDocument?->createElement('div');
                if ($wrapper !== null) {
                    $wrapper->appendChild($child->cloneNode(true));
                    $this->blocks($wrapper, $cell, 0);
                }
            }
        }
    }

    private function image(DOMElement $node, AbstractContainer $container): void
    {
        $src = $node->getAttribute('src');

        if (preg_match('#^data:image/(png|jpe?g|gif);base64,(.+)$#s', $src, $mm)) {
            $file = self::tempFile('img');
            file_put_contents($file, base64_decode($mm[2]));
            $this->temps[] = $file;
        } else {
            $file = LegalDocMeta::publicImagePath($src);
        }
        if ($file === null || str_ends_with($file, '.svg') || ! ($size = @getimagesize($file))) {
            return;
        }

        // بحدّ عرض المحتوى ونسبة الصورة — كما `max-width: 100%` في المحرّر وPDF
        $maxPt = self::CONTENT_MM / 25.4 * 72;
        $widthPt = min($maxPt, $size[0] * 0.75);
        $container->addImage($file, ['width' => (int) $widthPt, 'height' => (int) round($widthPt * $size[1] / max(1, $size[0])), 'alignment' => Jc::CENTER]);
    }

    // ── النصّ داخل الفقرة ──

    /** @param array<string, mixed> $font */
    private function inlineChildren(DOMNode $node, TextRun|ListItemRun $run, array $font): void
    {
        foreach ($node->childNodes as $child) {
            $this->inline($child, $run, $font);
        }
    }

    /** @param array<string, mixed> $font */
    private function inline(DOMNode $node, TextRun|ListItemRun $run, array $font): void
    {
        if (! $node instanceof DOMElement) {
            $text = $this->clean((string) $node->textContent);
            if ($text !== '') {
                $run->addText($text, $this->font($font, $text));
            }

            return;
        }

        $tag = strtolower($node->tagName);
        if ($tag === 'br') {
            $run->addTextBreak();

            return;
        }
        if ($tag === 'a') {
            $text = $this->clean((string) $node->textContent);
            $run->addLink($node->getAttribute('href'), $text, $this->font(['color' => LegalDocStyle::LINK_COLOR, 'underline' => 'single'] + $font, $text));

            return;
        }

        $font = match ($tag) {
            'strong', 'b' => ['bold' => true] + $font,
            'em', 'i' => ['italic' => true] + $font,
            'u' => ['underline' => 'single'] + $font,
            's', 'del', 'strike' => ['strikethrough' => true] + $font,
            'mark' => ['bgColor' => $this->hex($node->getAttribute('data-color') ?: ($this->styles($node)['background-color'] ?? 'fef08a'))] + $font,
            default => $font,
        };

        $this->inlineChildren($node, $run, $this->fontFrom($this->styles($node), $font));
    }

    // ── الأنماط ──

    /** @return array<string, mixed> */
    private function baseFont(): array
    {
        return ['name' => LegalDocStyle::FONT, 'size' => LegalDocStyle::BODY_PT, 'color' => LegalDocStyle::TEXT_COLOR];
    }

    /**
     * النصّ العربيّ `rtl` ليأخذ Word خطّه وحجمه من خانة النصوص المركّبة (`cs` · `szCs`) — واللاتينيّ بلا `rtl` فلا
     * يُعامَل معاملة العربيّ.
     *
     * @param  array<string, mixed>  $font
     * @return array<string, mixed>
     */
    private function font(array $font, string $text): array
    {
        $resolved = ['rtl' => (bool) preg_match('/\p{Arabic}/u', $text)] + $font + $this->baseFont();
        $this->usedFonts[(string) $resolved['name']] = true;

        return $resolved;
    }

    /**
     * أنماط العنصر المضمَّنة (لون، حجم، خطّ، خلفيّة) فوق الموروث.
     *
     * @param  array<string, string>  $style
     * @param  array<string, mixed>  $font
     * @return array<string, mixed>
     */
    private function fontFrom(array $style, array $font): array
    {
        if (isset($style['color'])) {
            $font['color'] = $this->hex($style['color']);
        }
        if (isset($style['font-size']) && ($pt = $this->toPt($style['font-size'])) !== null) {
            $font['size'] = $pt;
        }
        if (isset($style['font-family'])) {
            $font['name'] = trim(explode(',', $style['font-family'])[0], " \"'");
        }
        if (isset($style['background-color'])) {
            $font['bgColor'] = $this->hex($style['background-color']);
        }

        return $font;
    }

    /**
     * فقرةٌ من اليمين بمحاذاتها وتباعد أسطرها — من المصدر الواحد وما حمله العنصر.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function paragraph(?DOMElement $node, array $extra = []): array
    {
        $style = $node !== null ? $this->styles($node) : [];
        $line = $style['line-height'] ?? $this->innerLineHeight($node);

        return $extra + [
            'bidi' => true,
            'alignment' => $this->align($style['text-align'] ?? ''),
            'spaceAfter' => (int) round(LegalDocStyle::BODY_PT * 0.85 * 20),
            'lineHeight' => is_numeric($line) ? (float) $line : LegalDocStyle::LINE_HEIGHT,
        ];
    }

    /** تباعد الأسطر من TipTap يُحمَل على نصّ الفقرة (`<span style="line-height">`) — وWord يضبطه للفقرة. */
    private function innerLineHeight(?DOMElement $node): ?string
    {
        if ($node === null) {
            return null;
        }
        foreach ($node->getElementsByTagName('span') as $span) {
            $value = $this->styles($span)['line-height'] ?? null;
            if ($value !== null && is_numeric($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * المحاذاة في فقرةٍ من اليمين: «start» يمينٌ و«end» يسار — لا «right/left» التي يقلبها Word في الفقرة ثنائيّة الاتّجاه.
     */
    private function align(string $textAlign): string
    {
        return match (strtolower(trim($textAlign))) {
            'center' => Jc::CENTER,
            'justify' => Jc::BOTH,
            'left' => Jc::END,
            default => Jc::START,
        };
    }

    /** @return array<string, string> */
    private function styles(DOMElement $node): array
    {
        $out = [];
        foreach (explode(';', $node->getAttribute('style')) as $rule) {
            if (str_contains($rule, ':')) {
                [$k, $v] = array_map('trim', explode(':', $rule, 2));
                if ($k !== '' && $v !== '') {
                    $out[strtolower($k)] = $v;
                }
            }
        }

        return $out;
    }

    private function toPt(string $value): ?float
    {
        if (! preg_match('/^([\d.]+)\s*(pt|px|em|rem)?$/i', trim($value), $m)) {
            return null;
        }
        $n = (float) $m[1];

        return match (strtolower($m[2] ?? 'px')) {
            'pt' => $n,
            'em', 'rem' => $n * LegalDocStyle::BODY_PT,
            default => round($n * 0.75, 1),
        };
    }

    private function hex(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#([0-9a-f]{3})$/i', $color, $m)) {
            return strtolower($m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2]);
        }
        if (preg_match('/^#([0-9a-f]{6})$/i', $color, $m)) {
            return strtolower($m[1]);
        }
        if (preg_match('/^rgba?\((\d+),\s*(\d+),\s*(\d+)/i', $color, $m)) {
            return sprintf('%02x%02x%02x', $m[1], $m[2], $m[3]);
        }

        return LegalDocStyle::TEXT_COLOR;
    }

    /** ملفٌّ مؤقّت — يُحذف بعد الحفظ (`build`). */
    private static function tempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new \RuntimeException('تعذّر إنشاء ملفٍّ مؤقّت لتصدير Word.');
        }

        return $path;
    }

    /** نصٌّ صالحٌ لـXML: بلا محارف تحكّم تُفسد الملفّ. */
    private function clean(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
    }

    // ── تضمين الخطوط (ECMA-376 §17.8.1: خطٌّ «مموَّه» ‎.odttf بمفتاحٍ GUID) ──

    private function embedFonts(string $path): void
    {
        $fonts = array_values(array_filter(self::EMBEDDABLE, fn (string $name) => isset($this->usedFonts[$name])));
        if ($fonts === []) {
            return;
        }

        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return;
        }

        $entries = '';
        $rels = '';
        $n = 0;
        foreach ($fonts as $name) {
            $embeds = '';
            foreach (['Regular' => 'embedRegular', 'Bold' => 'embedBold'] as $weight => $element) {
                $file = resource_path("fonts/legal/{$name}-{$weight}.ttf");
                if (! is_file($file)) {
                    continue;
                }
                $n++;
                $h = strtoupper(bin2hex(random_bytes(16)));
                $guid = sprintf('{%s-%s-%s-%s-%s}', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
                $zip->addFromString("word/fonts/font{$n}.odttf", self::obfuscate((string) file_get_contents($file), $guid));
                $rels .= '<Relationship Id="rIdFont'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/font" Target="fonts/font'.$n.'.odttf"/>';
                $embeds .= '<w:'.$element.' r:id="rIdFont'.$n.'" w:fontKey="'.$guid.'"/>';
            }
            $entries .= '<w:font w:name="'.$name.'"><w:charset w:val="B2"/><w:family w:val="auto"/><w:pitch w:val="variable"/>'.$embeds.'</w:font>';
        }

        $table = (string) $zip->getFromName('word/fontTable.xml');
        $zip->addFromString('word/fontTable.xml', str_replace('</w:fonts>', $entries.'</w:fonts>', $table));
        $zip->addFromString('word/_rels/fontTable.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');

        $types = (string) $zip->getFromName('[Content_Types].xml');
        if (! str_contains($types, 'Extension="odttf"')) {
            $types = str_replace('<Default Extension="xml"', '<Default Extension="odttf" ContentType="application/vnd.openxmlformats-officedocument.obfuscatedFont"/><Default Extension="xml"', $types);
            $zip->addFromString('[Content_Types].xml', $types);
        }

        // أوّل عناصر الإعدادات بحسب ترتيب المخطّط — Word يرفض الملفّ إن جاء بعد غيره
        $settings = (string) $zip->getFromName('word/settings.xml');
        $settings = (string) preg_replace('#(<w:settings[^>]*>)#', '$1<w:embedTrueTypeFonts/>', $settings, 1);
        $zip->addFromString('word/settings.xml', $settings);

        $zip->close();
    }

    /** يُقلَب ترتيب بايتات المفتاح ثمّ يُطبَّق XOR على أوّل ٣٢ بايتاً من الخطّ — كما يقرؤه Word وLibreOffice. */
    public static function obfuscate(string $font, string $guid): string
    {
        $key = strrev((string) hex2bin(str_replace(['{', '}', '-'], '', $guid)));
        for ($i = 0; $i < 32 && $i < strlen($font); $i++) {
            // XOR بايتين لا يتجاوز 255 — والقناع يصرّح بالمدى لمحلّل PHP 8.5 (`chr` يشترط int<0,255>) بلا تغيير قيمة
            $font[$i] = chr((ord($font[$i]) ^ ord($key[$i % 16])) & 0xFF);
        }

        return $font;
    }

    // ── القوائم ──

    private function defineLists(): void
    {
        $levels = fn (string $format, callable $text) => array_map(fn (int $i) => [
            'format' => $format, 'text' => $text($i), 'alignment' => 'start',
            'left' => 360 * ($i + 1), 'hanging' => 360, 'tabPos' => 360 * ($i + 1),
        ], range(0, 4));

        $this->word->addNumberingStyle('legal-decimal', ['type' => 'multilevel', 'levels' => $levels('decimal', fn (int $i) => '%'.($i + 1).'.')]);
        $this->word->addNumberingStyle('legal-bullet', ['type' => 'multilevel', 'levels' => $levels('bullet', fn (int $i) => ['•', '◦', '▪', '•', '◦'][$i])]);
    }
}
