<?php

namespace App\Support;

/**
 * **نصّ الملخّص فقراتٍ وقوائم في المستندات المطبوعة** — نظير `RichText` في `resources/js/lib/consult-ui.tsx`
 * بالقواعد نفسها (ملاحظة المالك 2026-09-27): سطرٌ فارغ يفصل الفقرات، و«- » قائمة نقطيّة، و«1. » مرقّمة،
 * و`---` فاصل، و`### ` عنوان، و`**عريض**` و`*مائل*`.
 *
 * كان تقرير PDF يطبع النصّ خاماً (`pre-wrap`)، فتظهر النجوم والشرطات حروفاً. **كلّ نصٍّ يُهرَّب أوّلاً**
 * ثمّ تُبنى العناصر من قائمةٍ مغلقة — المصدر نموذجٌ توليديّ ومحامٍ يحرّر، فلا HTML منه يمرّ.
 */
final class SummaryText
{
    private const BULLET = '/^\s*[-*•·]\s+/u';

    private const NUMBER = '/^\s*[0-9٠-٩]+[.)،-]\s+/u';

    public static function html(?string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
        if ($text === '') {
            return '';
        }

        $out = [];
        $para = [];
        $list = null;
        $items = [];

        $flushPara = function () use (&$para, &$out): void {
            if ($para !== []) {
                $out[] = '<p>'.implode('<br>', array_map(self::inline(...), $para)).'</p>';
                $para = [];
            }
        };
        $flushList = function () use (&$list, &$items, &$out): void {
            if ($list !== null) {
                $out[] = "<{$list}>".implode('', array_map(fn ($i) => '<li>'.self::inline($i).'</li>', $items))."</{$list}>";
                $list = null;
                $items = [];
            }
        };

        foreach (explode("\n", $text) as $line) {
            $t = trim($line);
            $kind = match (true) {
                $t === '' => 'blank',
                (bool) preg_match('/^([-*_])\1{2,}$/', $t) => 'hr',
                (bool) preg_match('/^#{1,6}\s+/u', $t) => 'h',
                (bool) preg_match(self::BULLET, $line) => 'ul',
                (bool) preg_match(self::NUMBER, $line) => 'ol',
                default => 'p',
            };

            if ($kind === 'ul' || $kind === 'ol') {
                $flushPara();
                if ($list !== $kind) {
                    $flushList();
                    $list = $kind;
                }
                $items[] = (string) preg_replace($kind === 'ul' ? self::BULLET : self::NUMBER, '', $line);

                continue;
            }

            $flushList();
            match ($kind) {
                'blank' => $flushPara(),
                'hr' => [$flushPara(), $out[] = '<hr>'],
                'h' => [$flushPara(), $out[] = '<p><b>'.self::inline((string) preg_replace('/^#{1,6}\s+/u', '', $t)).'</b></p>'],
                default => $para[] = $t,
            };
        }
        $flushPara();
        $flushList();

        return implode('', $out);
    }

    /** يُهرَّب السطر ثمّ يُبنى التوكيد — نجمةٌ منفردة تبقى حرفاً. */
    private static function inline(string $line): string
    {
        $safe = e($line);
        $safe = (string) preg_replace('/\*\*([^*\n]+)\*\*/u', '<b>$1</b>', $safe);

        return (string) preg_replace('/(?<![*\w])\*([^*\s][^*\n]*?)\*(?![*\w])/u', '<i>$1</i>', $safe);
    }
}
