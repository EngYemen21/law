<?php

namespace App\Support;

/**
 * رحلة معالجة التذكرة — المراحل السبع المتسلسلة (تطابق TKT_LIFE في الواجهة).
 * كل مرحلة: الحالة + لون الشارة + المتحدّث + الدور + نصّ يُضاف للمحادثة.
 */
class TicketJourney
{
    public const STAGES = [
        ['label' => 'استلام الطلب', 'status' => 'جديدة', 'tone' => 'b-grey', 'who' => 'ai', 'role' => 'استقبال', 'msg' => 'تم استلام طلبكم بنجاح، وهو الآن قيد المعالجة.'],
        ['label' => 'التحليل', 'status' => 'قيد التحليل', 'tone' => 'b-blue', 'who' => 'ai', 'role' => 'تحليل', 'msg' => 'جارٍ تحليل طلبكم: قراءة المرفقات، تصنيف الموضوع، وتحديد القسم المختص.'],
        ['label' => 'الإحالة للقسم', 'status' => 'محالة للقسم القانوني', 'tone' => 'b-blue', 'who' => 'ai', 'role' => 'إحالة', 'msg' => 'تمت إحالة طلبكم إلى القسم القانوني المختص لدراسة الموضوع.'],
        ['label' => 'الرأي القانوني', 'status' => 'الرأي القانوني', 'tone' => 'b-cyan', 'who' => 'lawyer', 'role' => 'مستشار', 'msg' => 'تمت الدراسة المبدئية من المستشار القانوني. ولإبداء الرأي الكامل ومناقشة التفاصيل نأمل حجز استشارة قانونية.'],
        ['label' => 'حجز الاستشارة', 'status' => 'بانتظار حجز الاستشارة', 'tone' => 'b-amber', 'who' => 'ai', 'role' => 'مواعيد', 'msg' => 'يمكنكم الآن حجز موعد الاستشارة من قسم «حجز استشارة»، وستصلكم بطاقة الموعد.'],
        ['label' => 'الجلسة', 'status' => 'موعد مؤكد', 'tone' => 'b-green', 'who' => 'ai', 'role' => 'الجلسة', 'msg' => 'تم تأكيد موعد الجلسة. تُوثّق الجلسة ويُعدّ محضرها والتوصيات.'],
        ['label' => 'النتيجة', 'status' => 'مكتملة', 'tone' => 'b-green', 'who' => 'lawyer', 'role' => 'النتيجة', 'msg' => 'اكتملت معالجة طلبكم. ملخص الاستشارة والتوصيات والإجراءات المقترحة متاحة داخل التذكرة.'],
    ];

    /**
     * حالات بديلة لا تُنشئها الرحلة لكنها مقبولة ومعروضة (فهرس المرحلة + نغمة الشارة).
     * مصدر وحيد: تتغذّى منه قائمة الموظف والتحقّق من المدخلات، فلا تتفرّق المفردات.
     */
    public const ALIASES = [
        'بانتظار مستندات' => ['at' => 1, 'tone' => 'b-amber'],
        'بانتظار اعتماد المستشار' => ['at' => 2, 'tone' => 'b-amber'],
        'بانتظار الدفع' => ['at' => 4, 'tone' => 'b-amber'],
        'قيد التنفيذ' => ['at' => 5, 'tone' => 'b-blue'],
        'بانتظار اعتماد النتيجة' => ['at' => 5, 'tone' => 'b-amber'],
        'بانتظار اعتماد الإدارة' => ['at' => 6, 'tone' => 'b-amber'],
        'مغلقة' => ['at' => 6, 'tone' => 'b-grey'],
    ];

    /** حالات ينتظر فيها الموظف طرفاً آخر (محامٍ/إدارة/عميل) — لا يتقدّم فيها بنفسه */
    public const AWAITING_OTHERS = [
        'بانتظار اعتماد المستشار', 'بانتظار اعتماد النتيجة', 'بانتظار اعتماد الإدارة',
        'بانتظار حجز الاستشارة', 'بانتظار الدفع',
    ];

    /** الموعد قائم: تُعقد الجلسة ويُوثّق محضرها بدل التقدّم المباشر للنتيجة */
    public const SESSION_READY = ['موعد مؤكد', 'قيد التنفيذ'];

    // فهرس المرحلة الحالية من الحالة
    public static function indexOf(string $status): int
    {
        foreach (self::STAGES as $i => $s) {
            if ($s['status'] === $status) {
                return $i;
            }
        }

        return self::ALIASES[$status]['at'] ?? 0;
    }

    /**
     * كل الحالات المقبولة مرتّبة بمسار الرحلة — تُغذّي قائمة الموظف والتحقّق من المدخلات.
     *
     * @return list<array{status:string,tone:string}>
     */
    public static function options(): array
    {
        $all = [];
        foreach (self::STAGES as $i => $s) {
            $all[] = ['status' => $s['status'], 'tone' => $s['tone'], 'at' => $i];
        }
        foreach (self::ALIASES as $status => $a) {
            $all[] = ['status' => $status, 'tone' => $a['tone'], 'at' => $a['at']];
        }

        // ترتيب مستقرّ بمسار الرحلة: المرحلة الأصلية أولاً ثم بدائلها
        usort($all, fn ($x, $y) => $x['at'] <=> $y['at']);

        return array_map(fn ($o) => ['status' => $o['status'], 'tone' => $o['tone']], $all);
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_column(self::options(), 'status');
    }

    /**
     * النغمة القانونية للحالة — مصدر وحيد يمنع أن تُلوَّن الحالة نفسها لونين
     * باختلاف من كتبها (كان المستشار يغلق التذكرة رمادياً والموظف أخضر).
     */
    public static function toneFor(string $status): string
    {
        foreach (self::STAGES as $s) {
            if ($s['status'] === $status) {
                return $s['tone'];
            }
        }

        return self::ALIASES[$status]['tone'] ?? 'b-blue';
    }

    public static function isLast(string $status): bool
    {
        return self::indexOf($status) >= count(self::STAGES) - 1;
    }

    /**
     * هل تغيير الحالة اليدوي (قائمة «تغيير الحالة») مشروع؟ **للتصحيح بنفس المرحلة فقط**:
     * تبديل حالة بديلة ضمن نفس رقم المرحلة. يمنع أي قفز للأمام أو تراجع للخلف بين الفهارس.
     * التقدّم بين المراحل حصراً بزرّ «تنفيذ المرحلة التالية» (advance).
     */
    public static function canTransition(string $from, string $to): bool
    {
        return self::indexOf($to) === self::indexOf($from);
    }

    // المرحلة التالية، أو null إذا اكتملت
    public static function next(string $status): ?array
    {
        $i = self::indexOf($status) + 1;

        return self::STAGES[$i] ?? null;
    }
}
