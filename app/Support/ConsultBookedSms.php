<?php

namespace App\Support;

use App\Models\Consult;

/**
 * **رسالة تأكيد موعد الاستشارة للعميل** (قرار المالك 2026-10-03): تُرسل عند اعتماد الموعد ونشره
 * (`HandleAppointmentPublished`) — فيها رقم الاستشارة ونوعها وتاريخها ووقتها. كان العميل لا يعرف موعده
 * برسالةٍ نصّيّة إلّا قبله بدقائق. ونقلُ الموعد يمرّ بالنشر من جديد، فتصله رسالةٌ بالموعد الجديد.
 */
final class ConsultBookedSms
{
    public static function send(Consult $consult): bool
    {
        if ($consult->starts_at === null) {
            return false;
        }

        return ClientSms::send($consult->user, self::body($consult));
    }

    /** قصيرٌ ما أمكن: الرسالة العربيّة تُحاسَب بسبعين حرفاً للمقطع. */
    public static function body(Consult $consult): string
    {
        $type = $consult->channel ?: 'استشارة';

        return "تأكّد موعد استشارتك {$consult->ref} ({$type}): ".SessionLinkSms::when($consult->starts_at)
            .' — '.SettingsRegistry::str('office_name');
    }
}
