<?php

namespace App\Support;

use App\Models\Ticket;
use App\Models\TicketSummary;

/**
 * يبني نص ملخص الجلسة وبطاقة النتيجة النهائية (الوقائع/التوصيات/الإجراءات) — يطابق tfResult.
 */
class TicketResult
{
    /** نصّ نتيجة الجلسة المخزّن (يُشتق من الملخص المعتمد + محضر الجلسة). */
    public static function compose(Ticket $ticket, ?TicketSummary $summary): string
    {
        $facts = $summary?->facts ?: "• تحديد محل الطلب والنقاط القانونية الجوهرية لقضية «{$ticket->type}».";
        $recs = $summary?->key_points ?: '• اتخاذ الإجراء النظامي الأنسب بعد الدراسة.';

        return "الوقائع:\n{$facts}\n\nالتوصيات والإجراءات:\n{$recs}";
    }

    /** بطاقة النتيجة النهائية HTML المعروضة للعميل. */
    public static function card(Ticket $ticket, ?TicketSummary $summary): string
    {
        $facts = self::list($summary?->facts ?: "تحديد محل الطلب والنقاط القانونية الجوهرية لقضية «{$ticket->type}».");
        $recs = self::list($summary?->key_points ?: 'اتخاذ الإجراء النظامي الأنسب بعد الدراسة.');
        $lawyer = $ticket->assigned_lawyer ?: 'المستشار القانوني';

        return '<p>تم الانتهاء من دراسة الموضوع. ملخص الاستشارة والإجراءات المقترحة متاحة داخل التذكرة.</p>'
            .'<div class="result-card"><h3>ملخص الاستشارة</h3>'
            .'<div class="result-sec"><div class="t">الوقائع</div>'.$facts.'</div>'
            .'<div class="result-sec"><div class="t">التوصيات</div>'.$recs.'</div>'
            .'<div class="result-sec"><div class="t">الإجراءات / المهام</div><ul>'
            .'<li>تنفيذ التوصيات أعلاه — المسؤول: '.e($lawyer).'.</li>'
            .'<li>متابعة المهلة النظامية ثم التصعيد عند الحاجة.</li></ul></div></div>';
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
