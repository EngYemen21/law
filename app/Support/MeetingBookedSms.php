<?php

namespace App\Support;

use App\Models\Meeting;
use App\Models\User;

/**
 * **رسالة تأكيد موعد الاجتماع للعميل** (قرار المالك 2026-10-03) — نظير `ConsultBookedSms`.
 *
 * كان العميل يعلم باجتماعه عند إنشائه بإشعارٍ في الحساب وبريدٍ فقط، ولا تصله رسالةٌ نصّيّة إلّا عند فتح
 * الدخول (`SessionLinkSms`). تُرسل حين يُجدوَل الاجتماع مع عميل: إنشاؤه من «إدارة الاجتماعات»
 * (`Staff\MeetingController::store`) ونشر الدعوة (`MeetInvitation::announce`). والاجتماع الداخليّ بلا عميل فلا رسالة.
 */
final class MeetingBookedSms
{
    public static function send(Meeting $meeting, ?User $client): bool
    {
        if ($client === null || $meeting->starts_at === null) {
            return false;
        }

        return ClientSms::send($client, self::body($meeting));
    }

    /** قصيرٌ ما أمكن: الرسالة العربيّة تُحاسَب بسبعين حرفاً للمقطع. */
    public static function body(Meeting $meeting): string
    {
        return "تأكّد موعد اجتماعك {$meeting->ref}: ".SessionLinkSms::when($meeting->starts_at)
            .' — '.SettingsRegistry::str('office_name');
    }
}
