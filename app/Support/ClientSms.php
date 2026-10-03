<?php

namespace App\Support;

use App\Jobs\SendSmsJob;
use App\Models\User;
use App\Services\TaqnyatSmsService;

/**
 * **رسالةٌ نصّيّة إلى جوال العميل المسجَّل في حسابه** — المدخل الواحد لرسائل العميل الآليّة
 * (تأكيد موعد الاستشارة · تذكيرها · رابط الجلسة). لا تُرسل إلّا إلى رقم الحساب نفسه وبرقمٍ صالح،
 * ولا شيء بلا مزوّدٍ مهيّأ. والإرسال في الطابور (`SendSmsJob`)، فلا يؤخّر الطلب ولا يُسقطه فشلُ المزوّد.
 */
final class ClientSms
{
    /** يعيد `false` إن لم تُصفّ الرسالة (بلا رقمٍ صالح أو بلا مزوّد) — فيُعاد التذكير في تشغيلٍ لاحق. */
    public static function send(?User $client, string $body): bool
    {
        $phone = (string) ($client->phone ?? '');

        if ($phone === '' || ! Phone::isSendable($phone) || ! app(TaqnyatSmsService::class)->isConfigured()) {
            return false;
        }

        SendSmsJob::dispatch(Phone::intl($phone), $body);

        return true;
    }
}
