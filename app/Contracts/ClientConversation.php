<?php

namespace App\Contracts;

/**
 * **ملفٌّ له محادثةٌ مع العميل** — التذكرة والقضيّة والتنفيذ. يقرؤه `App\Support\AiClientVoice` ليقرّر هل
 * يكلّم الذكاءُ الاصطناعيّ العميلَ أم يكتب للطاقم وحده، بقاعدةٍ واحدة بدل شروطٍ متفرّقة في كلّ مهمّة.
 * و`hasHumanStaffMessage` يوفّره `App\Models\Concerns\HasClientConversation`.
 */
interface ClientConversation
{
    /** الملفّ ما زال قائماً يُخاطَب فيه العميل (لا منتهٍ ولا مغلق ولا مرفوض). */
    public function isOpenForClient(): bool;

    /** كتب إنسانٌ من المكتب (موظّف · محامٍ · إدارة) رسالةً ظاهرةً للعميل في محادثة الملفّ؟ */
    public function hasHumanStaffMessage(): bool;
}
