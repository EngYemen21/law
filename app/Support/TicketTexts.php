<?php

namespace App\Support;

class TicketTexts
{
    public const ESCALATION_WORDS = ['عاجل', 'مستعجل', 'استعجال', 'شكوى', 'أشتكي', 'اشتكي', 'تأخر', 'تأخير', 'متأخر', 'سيئ', 'سيء'];

    public const AUDIT_AUTO_TRIAGE = 'فرز آلي عند الفتح: القسم «%s» · الأولوية «%s» · طُلبت المستندات في رسالة ترحيب واحدة.';

    public const AUDIT_HUMAN_REQUIRED = 'حاجة لتدخّل بشري — %s';

    public const NOTIFICATION_HUMAN_REQUIRED = 'طلبك (%s) يحتاج متابعة من أحد موظفينا، وسيتولّاه المختص قريباً.';

    public const AUDIT_FAILED_ANALYSIS = 'تعذّر الفحص الآلي للمستند «%s» — لن تتم الإحالة آلياً؛ يراجعه الموظف ويُحيل يدوياً.';
}
