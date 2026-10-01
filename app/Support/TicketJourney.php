<?php

namespace App\Support;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Models\Ticket;

/**
 * رحلة معالجة التذكرة — المراحل السبع المتسلسلة (تطابق TKT_LIFE في الواجهة).
 * كل مرحلة: الحالة + لون الشارة + المتحدّث + الدور + نصّ يُضاف للمحادثة.
 */
class TicketJourney
{
    /**
     * ونصّ كل مرحلة **يصف موقع الملفّ لا عملاً وقع**: هذه قوالب يكتبها
     * `Employee\TicketController::advance` بضغطة **موظّف**، ثم تُنسب إلى
     * `who='lawyer'` باسم «المستشار القانوني» وتُعرض للعميل «الفريق القانوني».
     * فقولُ «تمت الدراسة المبدئية من المستشار» يُخبر العميل بعملٍ لم يقع،
     * وينسب إلى محامٍ ما كتبه قالبٌ وأطلقه موظّف.
     */
    public const STAGES = [
        ['label' => 'استلام الطلب', 'status' => 'جديدة', 'tone' => 'b-grey', 'who' => 'ai', 'role' => 'استقبال', 'msg' => 'تم استلام طلبكم بنجاح، وهو الآن قيد المعالجة.'],
        ['label' => 'التحليل', 'status' => 'قيد التحليل', 'tone' => 'b-blue', 'who' => 'ai', 'role' => 'تحليل', 'msg' => 'جارٍ تحليل طلبكم: قراءة المرفقات، تصنيف الموضوع، وتحديد القسم المختص.'],
        ['label' => 'الإحالة للقسم', 'status' => 'محالة للقسم القانوني', 'tone' => 'b-blue', 'who' => 'ai', 'role' => 'إحالة', 'msg' => 'تمت إحالة طلبكم إلى القسم القانوني المختص لدراسة الموضوع.'],
        ['label' => 'الرأي القانوني', 'status' => 'الرأي القانوني', 'tone' => 'b-cyan', 'who' => 'lawyer', 'role' => 'مستشار', 'msg' => 'بلغ ملفّكم مرحلة الرأي القانوني وهو لدى المستشار المختصّ. ولإبداء الرأي الكامل ومناقشة التفاصيل نأمل حجز استشارة قانونية.'],
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
        // اعتمد المستشار الملخّص ويُنتظر اعتماد الإدارة قبل وصوله للعميل (قرار المالك 2026-09-14)
        'بانتظار اعتماد الإدارة للملخّص' => ['at' => 2, 'tone' => 'b-amber'],
        'بانتظار تحديد الموعد' => ['at' => 4, 'tone' => 'b-amber'],
        // انتهت الجلسة ويُعدّ ملخّصها — يكتمل الملفّ باعتماد الإدارة له
        'بانتظار ملخّص الجلسة' => ['at' => 5, 'tone' => 'b-amber'],
        'بانتظار قرار المآل' => ['at' => 6, 'tone' => 'b-amber'],
        'بانتظار اعتماد الإدارة للمسار' => ['at' => 6, 'tone' => 'b-amber'],
        'محولة إلى قضية' => ['at' => 6, 'tone' => 'b-green'],
        'محولة إلى تنفيذ' => ['at' => 6, 'tone' => 'b-amber'],
        'مغلقة' => ['at' => 6, 'tone' => 'b-grey'],
    ];

    /** حالات ينتظر فيها الموظف طرفاً آخر (محامٍ/إدارة/عميل) — لا يتقدّم فيها بنفسه */
    public const AWAITING_OTHERS = [
        'بانتظار اعتماد المستشار', 'بانتظار حجز الاستشارة',
        'بانتظار اعتماد الإدارة للملخّص', 'بانتظار تحديد الموعد', 'بانتظار ملخّص الجلسة',
        'بانتظار قرار المآل',
    ];

    /** مراحل ما قبل اعتماد المستشار للملخّص — فيها وحدها يُحال الملفّ وتُطلب نواقصه من الموظّف. */
    public const BEFORE_LAWYER_APPROVAL = [
        'جديدة', 'قيد التحليل', 'بانتظار مستندات', 'محالة للقسم القانوني', 'بانتظار اعتماد المستشار',
    ];

    /**
     * **تبويبا العميل «التحليل» و«الرأي والاستشارة» — مجموعةٌ واحدة للخادم والواجهة.**
     *
     * كانت الشاشة وعدّاد الخادم يسردان الحالات باليد، فتسقط منهما حالات الرحلة الجديدة
     * (حالتا الاعتماد، «بانتظار تحديد الموعد»، «بانتظار ملخّص الجلسة») فلا تظهر التذكرة
     * إلا في «الكل».
     */
    public const CLIENT_PHASES = [
        'analysis' => [
            'جديدة', 'قيد التحليل', 'محالة للقسم القانوني', 'بانتظار اعتماد المستشار', 'بانتظار اعتماد الإدارة للملخّص',
        ],
        'opinion' => [
            'الرأي القانوني', 'بانتظار حجز الاستشارة', 'بانتظار تحديد الموعد', 'موعد مؤكد', 'بانتظار ملخّص الجلسة',
            'بانتظار قرار المآل',
        ],
    ];

    /** تبويب العميل الذي تقع فيه الحالة، أو `null` لما خارجهما (المستندات/الدفع/النهايات). */
    public static function clientPhase(string $status): ?string
    {
        foreach (self::CLIENT_PHASES as $phase => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * **أولويّة التذكرة — مصدرٌ واحد** (نظير `Consult::PRIORITIES`).
     *
     * كان الكتالوج غائباً، فتفرّقت المفردات: شاشة فتح التذكرة تكتب **عالية/متوسطة/منخفضة**
     * (وهي وحدها ما في القاعدة)، ومرشّح المحامي يعرض **عاجلة/عادية/منخفضة** — فلا خيارَ
     * منها يطابق صفّاً، والقيمتان الغالبتان غير معروضتين. وفرزُ الإدارة «العاجلة أولاً»
     * يرتّب `'عاجلة'` أوّلاً وهي لا وجود لها، فتسقط `'عالية'` في `ELSE` **دون** المتوسّطة.
     * وخمسُ مفرداتٍ ميّتة (طارئة · عاجل جداً · حرجة · urgent · high) موزَّعةٌ على أربع شاشات.
     */
    public const PRIORITIES = ['عالية', 'متوسطة', 'منخفضة'];

    /** الأولويّة الافتراضيّة حين لا يختار المستخدم — تطابق `default` في الهجرة. */
    public const PRIORITY_DEFAULT = 'متوسطة';

    /**
     * رتبةُ الفرز «الأعلى أولاً» — مصدرٌ واحد يخدم SQL والواجهة، فلا يتباعد ترتيبان.
     *
     * @var array<string, int>
     */
    public const PRIORITY_RANK = ['عالية' => 1, 'متوسطة' => 2, 'منخفضة' => 3];

    /** تعبير `CASE` لفرز الأولويّة في SQL — مبنيٌّ من الكتالوج لا مكتوباً بيد. */
    public static function prioritySql(string $column = 'priority'): string
    {
        $cases = '';
        foreach (self::PRIORITY_RANK as $label => $rank) {
            $cases .= "WHEN {$column} = '{$label}' THEN {$rank} ";
        }

        return 'CASE '.$cases.'ELSE '.(count(self::PRIORITY_RANK) + 1).' END';
    }

    /** أهي الأولويّة العليا؟ — تُقرأ في العدّادات والشارات بدل قوائم مفرداتٍ متفرّقة. */
    public static function isUrgent(?string $priority): bool
    {
        return $priority === self::PRIORITIES[0];
    }

    /**
     * **لماذا لا تُطلب استشارة على هذه التذكرة؟** — `null` حين يجوز.
     *
     * كان الحارس `indexOf > 5` يسمح بطلبها في أيّ مرحلةٍ حتى «موعد مؤكد»: فتُحوَّل تذكرةٌ
     * بلا ملخّص إلى طريقٍ مسدود (ع١٣)، وتُطلب استشارةٌ ثانية فوق موعدٍ قائم فترتدّ التذكرة (ع١٧).
     * الاستشارة تُطلب بعد نشر الرأي القانونيّ المبدئيّ، مصدرٌ واحد لكلّ المداخل.
     */
    public static function consultRequestBlocker(Ticket $ticket): ?string
    {
        if (! in_array($ticket->status, ['الرأي القانوني', 'بانتظار حجز الاستشارة'], true)) {
            return 'تُطلب الاستشارة بعد اعتماد الرأي القانوني المبدئيّ للملفّ.';
        }

        // الملخّص المعتمد، أو **قرار الإدارة العليا بمسار الاستشارة** — والقرار قد يُتّخذ بتجاوز الملخّص
        // المسبَّب (`OutcomeSummaryGate`) فلا ملخّص معتمداً؛ كان الحارس يردّه فيعلق العميل بعد إشعاره
        // «يمكنك الآن حجز الموعد» (ثبت في المتصفّح، قرار المالك 2026-09-30)
        if ($ticket->summary?->isApproved() || self::consultationTrackApproved($ticket)) {
            return null;
        }

        return 'تُطلب الاستشارة بعد اعتماد الإدارة لملخّص الملفّ.';
    }

    /** اعتمدت الإدارة العليا مسار «طلب استشارة قانونية» لهذه التذكرة (`ApproveOutcomeTrack`). */
    private static function consultationTrackApproved(Ticket $ticket): bool
    {
        return $ticket->approved_track === TicketOutcomeTrack::Consultation->value && $ticket->approved_track_at !== null;
    }

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

    // المرحلة التالية، أو null إذا اكتملت
    public static function next(string $status): ?array
    {
        $i = self::indexOf($status) + 1;

        return self::STAGES[$i] ?? null;
    }
}
