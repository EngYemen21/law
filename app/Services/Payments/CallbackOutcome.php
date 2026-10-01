<?php

namespace App\Services\Payments;

/**
 * **نتيجة العودة من صفحة الدفع** — ثلاث حالاتٍ يقرؤها العميل مختلفة: سُوّيت، أو رفضتها البوّابة فلم يُخصم شيء، أو لم
 * تتأكّد بعد (والإشعار يسوّيها إن كان المال قد خُصم). كانت الأخيرتان رسالةً واحدة: «إن كان قد خُصم…» على بطاقةٍ رُفضت.
 */
enum CallbackOutcome
{
    case Settled;
    case Declined;
    case Unconfirmed;

    public function settled(): bool
    {
        return $this === self::Settled;
    }

    /** رسالة غير النجاح — مصدرٌ واحد بدل نصٍّ مكرّر في متحكّمات العودة الأربعة. */
    public function failureMessage(): string
    {
        return $this === self::Declined
            ? 'رُفضت عملية الدفع ولم يُخصم أيّ مبلغ — يمكنك المحاولة مجدداً.'
            : 'تعذّر تأكيد الدفع. إن كان قد خُصم فسيُحدَّث تلقائياً، أو حاول مجدداً.';
    }
}
