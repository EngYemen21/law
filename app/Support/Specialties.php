<?php

namespace App\Support;

use App\Models\LegalDepartment;

/**
 * **واجهة التخصّصات القديمة — غلافٌ فوق كتالوج الأقسام.**
 *
 * كانت هنا قائمةٌ ثابتة من ١٤ تخصّصاً ومرادفاتٌ ومطابقةٌ احتوائيّة. صار المصدر جداول الكتالوج
 * (`LegalCatalogue`)، وتبقى هذه الدوالّ بتوقيعها نفسه كي لا يتغيّر مَن يناديها بالنصّ.
 * الشيفرة الجديدة تسأل `LegalCatalogue` و`LawyerSpecialties` مباشرةً.
 */
class Specialties
{
    /** قيمة خاصة قديمة: محامٍ عام مسنَد إليه كل الأقسام — يطابق أي تخصّص مطلوب. */
    public const ALL_DEPARTMENTS = 'كل الأقسام';

    /**
     * أسماء الأقسام الفعّالة بترتيبها.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return LegalCatalogue::departments()->map(fn (LegalDepartment $d) => $d->name)->values()->all();
    }

    /**
     * الاسم المعتمد في الكتالوج لصياغةٍ ما — أو النصّ نفسه (بلا بادئة «قسم») حين لا يُطابَق.
     */
    public static function normalize(?string $raw): string
    {
        $text = trim((string) $raw);
        if ($text === '' || $text === self::ALL_DEPARTMENTS) {
            return $text;
        }

        $department = LegalCatalogue::resolveDepartment($text, loose: true);

        return $department !== null
            ? $department->name
            : trim(preg_replace('/^(ال)?قسم\s+/u', '', $text) ?? $text);
    }
}
