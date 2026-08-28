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

    /** صياغة محضر اجتماع رسمي من ملخص Zoom AI Companion. */
    public static function formatMinutes(string $title, ?string $when, ?string $participants, array $s): string
    {
        $out = "محضر اجتماع: {$title}\n";
        if ($when) {
            $out .= "التاريخ: {$when}\n";
        }
        if ($participants) {
            $out .= "الحاضرون: {$participants}\n";
        }

        if ($s['details'] !== []) {
            $out .= "\nأبرز ما دار في الاجتماع:\n";
            foreach ($s['details'] as $d) {
                $body = trim($d['summary']);
                if ($body !== '') {
                    $out .= '• '.$body."\n";
                }
            }
        } elseif (trim($s['overview']) !== '') {
            $out .= "\nأبرز ما دار في الاجتماع:\n• ".trim($s['overview'])."\n";
        }

        $steps = array_values(array_filter(array_map('trim', $s['next_steps'] ?? []), fn ($x) => $x !== ''));
        if ($steps !== []) {
            $out .= "\nالقرارات والخطوات التالية:\n".implode("\n", array_map(fn ($x) => '• '.$x, $steps))."\n";
        }

        return rtrim($out);
    }

    /** فحص ما إذا كان محضر الاجتماع يحتوي نصاً قالبياً افتراضياً. */
    public static function isPlaceholderMinutes(?string $text): bool
    {
        if (! $text || trim($text) === '') {
            return true;
        }

        $placeholders = [
            'إعداد المحضر',
            'تسجيل الجلسة',
            'تحويل الصوت إلى نص',
            'تعذّر التوليد الذكي',
            'يُرجى تدوين أبرز ما دار',
            'بانتظار ملخص الجلسة',
        ];

        foreach ($placeholders as $ph) {
            if (mb_strpos($text, $ph) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * فحص ما إذا كان **الملخص** نصاً قالبياً — فيستبدله ملخص Zoom الحقيقي حين يصل.
     * يغطّي الصياغة الأمينة الحالية والنصوص القديمة الموجودة في سجلّات سابقة.
     */
    public static function isPlaceholderSummary(?string $text): bool
    {
        if (! $text || trim($text) === '') {
            return true;
        }

        $placeholders = [
            'بانتظار ملخص الجلسة',
            'تعذّر إعداد الملخّص',
            'بحاجة إلى تدوين المحضر يدوياً',
        ];

        foreach ($placeholders as $ph) {
            if (mb_strpos($text, $ph) !== false) {
                return true;
            }
        }

        return false;
    }
}
