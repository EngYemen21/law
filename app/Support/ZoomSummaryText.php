<?php

namespace App\Support;

/**
 * صياغة ملخّص AI Companion من Zoom نصّاً عربياً بعناوين واضحة — مشترك بين الاستشارة والاجتماع.
 */
class ZoomSummaryText
{
    /**
     * @param  string  $heading  السطر الأول، مثل «ملخص الاستشارة — CN-…» أو «ملخص الاجتماع — M-…»
     * @param  array{overview: string, details: array<int, array{label: string, summary: string}>, next_steps: array<int, string>}  $s
     */
    public static function format(string $heading, array $s): string
    {
        // لا نكشف للعميل أنّ الملخّص مُولّد آلياً — عنوان محايد فقط
        $out = $heading."\n";

        if (trim($s['overview']) !== '') {
            $out .= "\nنظرة عامة:\n".trim($s['overview'])."\n";
        }

        foreach ($s['details'] as $d) {
            $label = trim($d['label']);
            $body = trim($d['summary']);
            if ($body === '') {
                continue;
            }
            $out .= "\n".($label !== '' ? $label.":\n" : '').$body."\n";
        }

        $steps = array_values(array_filter(array_map('trim', $s['next_steps']), fn ($x) => $x !== ''));
        if ($steps !== []) {
            $out .= "\nالخطوات التالية:\n".implode("\n", array_map(fn ($x) => '• '.$x, $steps))."\n";
        }

        return rtrim($out);
    }
}
