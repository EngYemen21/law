<?php

namespace App\Support;

use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * **رسالة العميل النصّيّة عند فتح الدخول** (قرار المالك 2026-10-01، الخيار «ب»): رسالةٌ واحدة للجلسة
 * فيها تاريخها ووقتها ورابط غرفتها، تُرسل حين يُفتح زرّ الدخول (`session_join_opens_minutes`)
 * — من `zoom:release-links`، وختمُ `link_released_at` يمنع تكرارها. والتذكير القريب للمرئيّة إشعارٌ
 * في الحساب بلا رسالة، فلا يصل العميلَ رسالتان متقاربتان.
 *
 * **الرابط رابط غرفة المنصّة لا رابط Zoom:** الدخول يمرّ بتسجيل الدخول وحرّاس الغرفة، فرسالةٌ تصل
 * غير صاحبها لا تُدخله. ولا تُرسل إلّا إلى جوال العميل المسجَّل في حسابه وبرقمٍ صالح.
 */
final class SessionLinkSms
{
    public static function meeting(Meeting $meeting, User $client): bool
    {
        return self::send($client, 'اجتماعك '.$meeting->ref.' يبدأ', $meeting->starts_at, $meeting->joinLink($client));
    }

    public static function consult(Consult $consult, User $client): bool
    {
        return self::send($client, 'استشارتك المرئيّة '.$consult->ref.' تبدأ', $consult->starts_at, $consult->joinLink($client));
    }

    /** «الخميس 01 أكتوبر 2026 · 03:00 م» — صياغة الموعد في الرسائل النصّيّة (نظير `Consult::whenLabel`). */
    public static function when(CarbonInterface $startsAt): string
    {
        return $startsAt->copy()->locale('ar')->translatedFormat('l d F Y · h:i A');
    }

    /** نصّ الرسالة — قصيرٌ ما أمكن: الرسالة العربيّة تُحاسَب بسبعين حرفاً للمقطع. */
    public static function body(string $subject, CarbonInterface $startsAt, string $link): string
    {
        return "{$subject} ".self::when($startsAt).". ادخل من: {$link} — ".SettingsRegistry::str('office_name');
    }

    /** الإطلاق لا يمرّ إلّا بجلسةٍ لها موعد؛ وبلا موعدٍ لا رسالة (لا تاريخ تُعلنه). */
    private static function send(User $client, string $subject, ?CarbonInterface $startsAt, string $link): bool
    {
        if (! $startsAt) {
            return false;
        }

        return ClientSms::send($client, self::body($subject, $startsAt, $link));
    }
}
