<?php

namespace App\Jobs;

use App\Services\TaqnyatSmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * إرسال رسالة نصّية خارج طلب المستخدم أو تشغيل المجدول.
 *
 * لماذا مهمّة لا نداء مباشر: أوامر التذكير تعمل كل دقيقة تحت withoutOverlapping()،
 * ونداء تقنيات لوحظ بطؤه (مهلة 30ث)، فإرسال عدّة رسائل داخل التشغيل الواحد يُطيله
 * حتى يبتلع نافذته فتُتخطّى التشغيلات التالية ويتأخّر تذكير من بعده.
 *
 * الرقم يُمرَّر دوليّاً جاهزاً (لا كائن User) كي تبقى الحمولة صغيرة ولا يتغيّر الجوال
 * بين الجدولة والتنفيذ — الرسالة تذهب إلى الرقم الذي استُحقّ التذكير عليه.
 */
class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public string $intlPhone,
        public string $body,
    ) {}

    public function handle(TaqnyatSmsService $sms): void
    {
        $sms->sendTo($this->intlPhone, $this->body);
    }
}
