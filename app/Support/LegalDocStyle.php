<?php

namespace App\Support;

/**
 * **تنسيق المستند القانونيّ في الخادم** — نظير `resources/css/legal-document.css` (المصدر الواحد للمحرّر وPDF وWord).
 *
 * - `css()`: الملفّ نفسه لقالب PDF، وخطوطه بياناتٍ مضمَّنة (`data:`) — لا طلبَ لـGoogle وقت التوليد (كان يتعذّر فيخرج
 *   PDF بخطٍّ بديل تتفرّق فيه الشدّة والحركات).
 * - الثوابت: قيم الملفّ نفسها لملفّ Word ‏(`LegalDocx`) الذي لا يقرأ CSS — ويحرس تطابقهما `LegalDocumentExportTest`.
 */
final class LegalDocStyle
{
    public const FONT = 'Tajawal';

    public const BODY_PT = 14;

    public const LINE_HEIGHT = 1.85;

    public const TEXT_COLOR = '13314f';

    /** العناوين: المستوى ← [الحجم pt، اللون] */
    public const HEADINGS = [
        1 => [20, '0a2a55'],
        2 => [17, '0e5c9c'],
        3 => [15, '13314f'],
        4 => [14, '0a2a55'],
    ];

    public const TABLE_BORDER = 'ccd7e0';

    public const TABLE_HEAD_BG = 'f1f5f8';

    public const QUOTE_BORDER = '0e5c9c';

    public const QUOTE_BG = 'f8fafc';

    public const LINK_COLOR = '0e5c9c';

    /** هوامش الصفحة بالملّيمتر: أعلى، يمين، أسفل، يسار — PDF والطباعة وWord */
    public const PAGE_MARGINS_MM = [14, 14, 18, 14];

    /** عنوان المستند في رأسه (خارج المحتوى) */
    public const TITLE_PT = 21;

    public const TITLE_COLOR = '0a2a55';

    private static ?string $css = null;

    /** ملفّ التنسيق بخطوطه مضمَّنةً — لقالب PDF (Browsershot يصيّر HTML نصّاً بلا أصلٍ يُطلب منه ملفّ). */
    public static function css(): string
    {
        if (self::$css !== null) {
            return self::$css;
        }

        $css = (string) file_get_contents(resource_path('css/legal-document.css'));

        return self::$css = (string) preg_replace_callback(
            "#url\\('/fonts/legal/([a-z0-9\\-]+\\.woff2)'\\)#",
            function (array $m): string {
                $path = public_path('fonts/legal/'.$m[1]);

                return is_file($path)
                    ? "url('data:font/woff2;base64,".base64_encode((string) file_get_contents($path))."')"
                    : $m[0];
            },
            $css,
        );
    }

    /** «14mm 14mm 18mm 14mm» لقاعدة `@page`. */
    public static function pageMargins(): string
    {
        return implode(' ', array_map(fn (int $mm) => $mm.'mm', self::PAGE_MARGINS_MM));
    }
}
