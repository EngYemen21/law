<?php

namespace App\Support;

use App\Models\Ticket;
use App\Models\TicketSummary;

/**
 * يبني نص ملخص الجلسة وبطاقة النتيجة النهائية (الوقائع/التوصيات) — يطابق tfResult.
 *
 * **ما لا يُكتب هنا:** توصيةٌ لم يكتبها محامٍ، ومهمّةٌ لم يُسندها أحد.
 *
 * كانت البطاقة — وهي تصل العميل ضمن نتيجة معتمدة — تحمل بندين ثابتين دائماً:
 * «تنفيذ التوصيات أعلاه — المسؤول: [اسم المحامي]» و«متابعة المهلة النظامية ثم
 * التصعيد عند الحاجة». والأوّل **يُسند مهمّةً إلى محامٍ بالاسم** ولا سجلّ لها في
 * النظام (لا مهامّ مرتبطة بالتذكرة أصلاً)، والثاني يَعِد بمتابعة مهلةٍ لم تُحسب
 * وبتصعيدٍ لم يُقرَّر. وكلاهما التزامٌ يقرؤه العميل من وثيقة نتيجةٍ معتمدة.
 *
 * وكذلك بدائل الحقول الفارغة: «اتخاذ الإجراء النظامي الأنسب بعد الدراسة» تُقرأ
 * توصيةً وهي قالبٌ يظهر حين **لا توصية** — أي أنها تملأ الفراغ بادّعاء بدل أن تُعلنه.
 */
class TicketResult
{
    /** ما يُكتب حين يخلو الحقل — إعلانُ غيابٍ لا توصيةٌ مُختلَقة. */
    public const NO_FACTS = 'لم تُدوَّن وقائع الملفّ بعد.';

    public const NO_RECOMMENDATIONS = 'لم تُدوَّن توصيات بعد.';

    /** نصّ نتيجة الجلسة المخزّن (يُشتق من الملخص المعتمد + محضر الجلسة). */
    public static function compose(Ticket $ticket, ?TicketSummary $summary): string
    {
        $facts = $summary?->facts ?: self::NO_FACTS;
        $recs = $summary?->key_points ?: self::NO_RECOMMENDATIONS;

        return "الوقائع:\n{$facts}\n\nالتوصيات والإجراءات:\n{$recs}";
    }

    /** بطاقة النتيجة النهائية HTML المعروضة للعميل. */
    public static function card(Ticket $ticket, ?TicketSummary $summary): string
    {
        $facts = self::list($summary?->facts ?: self::NO_FACTS);
        $recs = self::list($summary?->key_points ?: self::NO_RECOMMENDATIONS);

        return '<p>تم الانتهاء من دراسة الموضوع. ملخص الاستشارة والإجراءات المقترحة متاحة داخل التذكرة.</p>'
            .'<div class="result-card"><h3>ملخص الاستشارة</h3>'
            .'<div class="result-sec"><div class="t">الوقائع</div>'.$facts.'</div>'
            .'<div class="result-sec"><div class="t">التوصيات</div>'.$recs.'</div>'
            .'</div>';
    }

    /** تحويل نقاط مفصولة بأسطر (تبدأ بـ •) إلى قائمة HTML. */
    private static function list(string $text): string
    {
        $items = array_filter(array_map(fn ($l) => trim(ltrim(trim($l), '•- ')), preg_split('/\r?\n/', $text)));
        if (empty($items)) {
            return '<ul><li>—</li></ul>';
        }

        return '<ul>'.implode('', array_map(fn ($i) => '<li>'.e($i).'</li>', $items)).'</ul>';
    }
}
