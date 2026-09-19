<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;

/**
 * **الحالات التي لم يُحسم فيها مآل التذكرة** — كلُّ حالات الكتالوج عدا النهايتين القطعيّتين
 * («محولة إلى قضية» و«مغلقة»).
 *
 * لماذا مصدرٌ مستقلّ: كتّابٌ قدامى نُقلوا إلى المحرّك كانوا يكتبون الحالة **بلا أيّ فحص**
 * (الوكيل الآليّ في الطابور مثلاً). قاعدة النقل ألّا يضيق `from()` عمّا كان ينجح، فالقائمة
 * واسعةٌ عمداً: تشمل «مكتملة» والقديمة المطويّة لصفوفٍ قائمة. والنهايتان وحدهما خارجها —
 * إحياءُ ملفٍّ حُسم مآله ليس انتقالاً مشروعاً من أيّ باب. وتُشتقّ من التعداد لا تُسرد باليد،
 * فحالةٌ تُضاف للكتالوج تدخلها تلقائيّاً.
 */
final class TicketOpenStates
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_values(array_map(
            fn (TicketStatus $s) => $s->value,
            array_filter(TicketStatus::cases(), fn (TicketStatus $s) => ! $s->isTerminal())
        ));
    }
}
