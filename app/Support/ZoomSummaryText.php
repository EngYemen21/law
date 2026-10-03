<?php

namespace App\Support;

/**
 * صياغة ملخّص AI Companion من Zoom نصّاً عربياً بعناوين واضحة — مشترك بين الاستشارة والاجتماع.
 */
class ZoomSummaryText
{
    /**
     * @param  string  $heading  السطر الأول، مثل «ملخص الاستشارة — CN-…» أو «ملخص الاجتماع — M-…»
     * @param  array{content?: string, overview: string, details: array<int, array{label: string, summary: string}>, next_steps: array<int, string>}  $s
     */
    public static function format(string $heading, array $s): string
    {
        // لا نكشف للعميل أنّ الملخّص مُولّد آلياً — عنوان محايد فقط
        $out = $heading."\n";

        // الحقل الموحّد (`summary_content`) يحمل الملخّص كاملاً بعناوينه — فلا تُضاف إليه الحقول القديمة فتتكرّر
        if (trim($s['content'] ?? '') !== '') {
            return rtrim($out."\n".trim($s['content']));
        }

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

        if (trim($s['content'] ?? '') !== '') {
            $out .= "\nأبرز ما دار في الاجتماع:\n".trim($s['content'])."\n";
        } elseif ($s['details'] !== []) {
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
        if ($steps !== [] && trim($s['content'] ?? '') === '') {
            $out .= "\nالقرارات والخطوات التالية:\n".implode("\n", array_map(fn ($x) => '• '.$x, $steps))."\n";
        }

        return rtrim($out);
    }

    /**
     * **هل كتب Zoom الملخّص بالعربيّة؟** — يُفحص نصّ Zoom وحده لا عناويننا العربيّة حوله.
     *
     * لغة الملخّص عند Zoom لغةُ أغلب المتحدّثين كما التقطها التعرّف على الكلام، ولغة الكلام الافتراضيّة
     * الإنجليزيّة ما لم تُضبط (Zoom KB0058013 · KB0062813) — فكلامٌ عربيّ يُفرَّغ إنجليزيّاً مشوّهاً ويُلخَّص
     * إنجليزيّاً. ثبت على CN-2026-6349 (قرار المالك 2026-10-03): ملخّصٌ كهذا لا يُنشر للعميل تلقائيّاً.
     * الحكم بأغلبيّة الحروف: العربيّة أكثر من اللاتينيّة.
     *
     * @param  array{content?: string, overview: string, details: array<int, array{label: string, summary: string}>, next_steps: array<int, string>}  $s
     */
    public static function isArabic(array $s): bool
    {
        $text = implode(' ', [
            $s['content'] ?? '',
            $s['overview'],
            ...array_map(fn (array $d) => $d['label'].' '.$d['summary'], $s['details']),
            ...$s['next_steps'],
        ]);

        $arabic = preg_match_all('/\p{Arabic}/u', $text);
        $latin = preg_match_all('/\p{Latin}/u', $text);

        return $arabic > 0 && $arabic > $latin;
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
            // احتياطيّ الاستشارة بنصّه الحرفيّ: كانت القائمة تحمل «تعذّر إعداد الملخّص»
            // والمكتوب فعلاً «تعذّر إعداد ملخّص الاستشارة» — فلا تطابق، فيبقى نصّ
            // التعذّر مكانه ومادّة Zoom الحقيقيّة لا تحلّ محلّه.
            'تعذّر إعداد ملخّص الاستشارة',
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
