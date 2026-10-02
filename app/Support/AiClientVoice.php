<?php

namespace App\Support;

use App\Contracts\ClientConversation;
use Illuminate\Database\Eloquent\Model;

/**
 * **متى يكلّم الذكاءُ الاصطناعيّ العميلَ — ومتى يكتب للطاقم وحده** (قرار المالك 2026-10-02).
 *
 * في محادثات التذكرة والقضيّة والتنفيذ تتوقّف الرسائل الآليّة الظاهرة للعميل في ثلاث حالات:
 *  1. **تدخّل إنسانٌ من المكتب** — كتب موظّفٌ أو محامٍ أو الإدارة العليا رسالةً ظاهرة: البشر يتولّون الحوار.
 *  2. **انتهى الملفّ** (`ClientConversation::isOpenForClient`).
 *  3. **المستند من رفع المكتب** لا العميل — لا يُخاطَب العميل بتحليل ما لم يرسله.
 *
 * والتحليل نفسه **يبقى**: يُكتب ملاحظةً داخليّة (`note`) على قناة الطاقم. والحالة تُقرأ **طازجة** هنا مرّةً،
 * فالمهامّ تُعاد حتى 24 ساعة وقد يتغيّر الملفّ في الأثناء. والمخرجات التي يعتمدها إنسان (دراسة التنفيذ،
 * لائحة الدعوى) ليست من هذا الباب: تُنشر باسم المعتمِد.
 */
final class AiClientVoice
{
    /** هل كتب إنسانٌ من المكتب في المحادثة رسالةً ظاهرةً للعميل؟ */
    public static function humanIntervened(ClientConversation $conversation): bool
    {
        return $conversation->hasHumanStaffMessage();
    }

    /** هل يجوز لمخرجٍ آليّ أن يصل العميلَ رسالةً ظاهرة الآن؟ */
    public static function maySpeak(ClientConversation $conversation, bool $fromClient = true): bool
    {
        $current = self::fresh($conversation);

        return $fromClient && $current->isOpenForClient() && ! self::humanIntervened($current);
    }

    /** نوع المرسِل لرسالةٍ آليّة: `ai` للعميل حين يجوز، وإلا ملاحظةٌ داخليّة للطاقم. */
    public static function who(ClientConversation $conversation, bool $fromClient = true): string
    {
        return self::maySpeak($conversation, $fromClient) ? 'ai' : 'note';
    }

    /**
     * **رسالة عميلٍ لن يردّ عليها الذكاء** — يُنبَّه مسؤول المحادثة (`handler_id`) أو المحامي المسنَد،
     * فلا تنتظر صامتةً بعد أن صار الحوار بشريّاً.
     */
    public static function handOff(ClientConversation&Model $conversation, string $label): void
    {
        $owner = $conversation->getAttribute('handler_id') ?: $conversation->getAttribute('assigned_lawyer_id');

        if ($owner) {
            Notify::send((int) $owner, 'folder', 't-blue', "رسالة جديدة من العميل على {$label} — يُرجى المتابعة.");
        }
    }

    private static function fresh(ClientConversation $conversation): ClientConversation
    {
        return $conversation instanceof Model ? ($conversation->fresh() ?? $conversation) : $conversation;
    }
}
