<?php

namespace App\Models\Concerns;

use App\Support\ChatSenderLabel;
use App\Support\ConversationHandler;
use App\Support\MessageSender;
use Illuminate\Database\Eloquent\Model;

/**
 * **رسالةُ محادثة تحفظ حسابَ مُرسِلها وعنوانَه — وتُبلغ مسؤوليّةَ المحادثة بمن ردّ.** (طلب المالك 2026-09-25)
 *
 * يُستعمل في نماذج الرسائل الثلاثة (التذكرة والقضيّة والتنفيذ). والالتقاط هنا لا في المتحكّمات:
 * الرسائل تُكتب من عشرات المواضع، وأيّ موضعٍ جديد يرث التسجيل بلا سطرٍ إضافيّ — وهي العائلة التي
 * كان يُنسى فيها حقلٌ في كلّ جولة.
 *
 * - `sender_id` و`sender_ip` خارج `$fillable` عمداً: لا يُكتبان من مدخلات الطلب، ومن أسندهما صراحةً
 *   (`forceFill`/إسنادٌ مباشر) لا يُكتب فوقه.
 * - ومخفيّان في `toArray`/JSON افتراضياً: علاقةٌ تُحمَّل وتُمرَّر خاماً إلى صفحةٍ لا تُسرّبهما.
 * - وبعد الإنشاء تُسأل `ConversationHandler` هل انتقلت مسؤوليّة المحادثة بهذه الرسالة.
 *
 * والنموذج يعرّف `conversation()`: الملفّ الذي تنتمي إليه الرسالة.
 */
trait RecordsSender
{
    public static function bootRecordsSender(): void
    {
        static::creating(function (self $message) {
            // لا يُسنَد null صراحةً: الصفّ بلا مُرسِلٍ بشريّ يُكتب كما كان تماماً
            if ($message->sender_id === null && ($id = MessageSender::userId($message->who)) !== null) {
                $message->sender_id = $id;
            }

            if ($message->sender_ip === null && ($ip = MessageSender::ip($message->who)) !== null) {
                $message->sender_ip = $ip;
            }
        });

        static::created(fn (self $message) => ConversationHandler::noteMessage($message));
    }

    public function initializeRecordsSender(): void
    {
        $this->makeHidden(['sender_ip', 'sender_id']);
    }

    /** الملفّ الذي تنتمي إليه الرسالة (تذكرة · قضيّة · تنفيذ). */
    abstract public function conversation(): ?Model;

    /**
     * **قناة البثّ اللحظيّ لهذه الرسالة — والملاحظة الداخليّة لا تُبثّ على قناةٍ يسمعها العميل.**
     *
     * كانت التذكرة وحدها تفصل الملاحظة إلى `{base}.staff`، وبثُّ القضيّة والتنفيذ يرسل كلّ رسالة
     * إلى القناة المشتركة: فتصل العميلَ لحظةَ كتابتها ملاحظةُ «أعادت الإدارة إسناد القضية إلى
     * <الاسم الكامل>» ونتيجةُ الدراسة الذكيّة الداخليّة — وشاشته تُخفيها رسماً، لكنّ الحمولة بين يديه.
     * فالقاعدة هنا مرّةً للنماذج الثلاثة، ويبنيها كلُّ بثٍّ من قاعدة قناته.
     */
    public function broadcastChannelName(string $base): string
    {
        return $this->who === 'note' ? $base.'.staff' : $base;
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
        if ($forClient || $this->sender_ip === null || ! MessageSender::visibleToViewer()) {
            return [];
        }

        return ['ip' => $this->sender_ip];
    }

    /**
     * اسمُ المُرسِل في شكل الرسالة: الطاقم يرى المخزَّن كما هو، والحمولة الموجّهة للعميل تأخذ
     * التسمية التي ضبطتها الإدارة (`ChatSenderLabel`) — بحساب المُرسِل الذي تعرفه الرسالة هنا.
     */
    protected function senderName(bool $forClient): string
    {
        return $forClient
            ? ChatSenderLabel::forClient($this->who, (string) $this->name, $this->sender_id)
            : (string) $this->name;
    }
}
