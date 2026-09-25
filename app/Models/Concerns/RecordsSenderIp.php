<?php

namespace App\Models\Concerns;

use App\Support\SenderIp;

/**
 * **رسالةُ محادثة تحفظ عنوان IP مُرسِلها، ولا تُسلّمه إلا للطاقم.** (طلب المالك 2026-09-25)
 *
 * يُستعمل في نماذج الرسائل الثلاثة (التذكرة والقضيّة والتنفيذ). والالتقاط في `creating` لا في
 * المتحكّمات: الرسائل تُكتب من عشرات المواضع، وأيّ موضعٍ جديد يرث التسجيل بلا سطرٍ إضافيّ.
 *
 * - العمود خارج `$fillable` عمداً: لا يُكتب من مدخلات الطلب فيُزوِّر العميلُ عنوانه، ومن أسنده
 *   صراحةً (`forceFill`/إسنادٌ مباشر) لا يُكتب فوقه.
 * - ومخفيٌّ في `toArray`/JSON افتراضياً: علاقةٌ تُحمَّل وتُمرَّر خاماً إلى صفحةٍ لا تُسرّبه.
 * - ويظهر في `toMessage()` عبر `senderIpField()` فقط، بقاعدة `SenderIp::visibleToViewer`.
 */
trait RecordsSenderIp
{
    public static function bootRecordsSenderIp(): void
    {
        static::creating(function (self $message) {
            if ($message->sender_ip !== null) {
                return;
            }

            // لا يُسنَد null صراحةً: الصفّ بلا مُرسِلٍ بشريّ يُكتب كما كان تماماً (والعمود يبقى null)
            $ip = SenderIp::forNewMessage($message->who);
            if ($ip !== null) {
                $message->sender_ip = $ip;
            }
        });
    }

    public function initializeRecordsSenderIp(): void
    {
        $this->makeHidden('sender_ip');
    }

    /**
     * حقل `ip` لشكل الرسالة في الواجهة — أو لا شيء.
     *
     * يُمنح بشرطين معاً: المشاهد من الطاقم، والحمولة ليست موجّهةً للعميل. والشرط الثاني لأنّ
     * المشاهد وحده لا يكفي: البثّ اللحظيّ يُبنى داخل طلب المُرسِل (موظّفاً كان) ثمّ يصل قناةً
     * يستمع إليها العميل — فيعلن الباني `forClient` ولا يُسأل عن المشاهد.
     *
     * والمفتاح يغيب كلّه لا يأتي فارغاً: حمولة العميل لا تحمل أثراً للحقل أصلاً.
     *
     * @return array{ip?: string}
     */
    protected function senderIpField(bool $forClient): array
    {
        if ($forClient || $this->sender_ip === null || ! SenderIp::visibleToViewer()) {
            return [];
        }

        return ['ip' => $this->sender_ip];
    }
}
