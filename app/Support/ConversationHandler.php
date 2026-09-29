<?php

namespace App\Support;

use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Conversation\TakeOverConversation;
use App\Domain\Journey\Workflow;
use App\Models\JourneyTransition;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **من يتولّى محادثة الملفّ، ومن تولّاها قبله.** (طلب المالك 2026-09-25)
 *
 * مصدرٌ واحد للسؤالين، على التذاكر والقضايا والتنفيذ:
 *
 * - `noteMessage` — يُنادى بعد إنشاء كلّ رسالة (`RecordsSender`): إن كانت **ردّاً** (طلبٌ على مسار ردٍّ
 *   موسومٍ بـ`conversation.reply`) كتبه **موظّفٌ أو إداريّ** والعميلُ يراه، وليس هو المسؤول، تنتقل
 *   المسؤوليّة إليه (`TakeOverConversation`). والدور من **الحساب**
 *   لا من `who`: ردُّ الإدارة على التذكرة يُخزَّن `who='lawyer'`، فالحكم بالنصّ كان سيخطئه.
 * - `history` — المسؤول الحاليّ وسجلّ التسليم والاستلام، لشاشات الطاقم وحدها.
 *
 * والمحامي خارج «مسؤول المحادثة»: له إسناده المستقلّ (`assigned_lawyer_id`)، وردُّه يُربط بحسابه
 * (`sender_id`) ولا ينقل المسؤوليّة.
 */
final class ConversationHandler
{
    /** رسائلُ لا يراها العميل أو ليست من المكتب — لا تنقل مسؤوليّة المحادثة. */
    private const NOT_A_REPLY = ['note', 'ai', 'system', 'client', 'me'];

    /** علامةُ «الطلب الجاري ردٌّ» — في الحاوية لا متغيّرٍ ثابت: جديدةٌ لكلّ طلبٍ واختبار. */
    private const REPLYING = 'conversation.replying';

    /**
     * يُجري `$write` على أنّه **ردٌّ يكتبه موظّف** — تضعه الوسيطة `conversation.reply` على مسارات الردّ.
     *
     * بلا هذه العلامة لا تنتقل المسؤوليّة: الإعلان الآليّ الذي يُكتب في المحادثة أثناء فعلٍ آخر
     * (اعتماد ملخّص · تحديد أتعاب · إغلاق قضيّة) كان يجعل المدير «مسؤول المحادثة» لأنّه اعتمد
     * ملخّصاً — والمالك قال «من **يردّ**».
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    public static function whileReplying(callable $write): mixed
    {
        app()->instance(self::REPLYING, true);

        try {
            return $write();
        } finally {
            app()->forgetInstance(self::REPLYING);
        }
    }

    public static function noteMessage(Model $message): void
    {
        if (! app()->bound(self::REPLYING)) {
            return;
        }

        $senderId = $message->getAttribute('sender_id');

        if ($senderId === null || in_array($message->getAttribute('who'), self::NOT_A_REPLY, true)) {
            return;
        }

        $conversation = $message->conversation();
        if ($conversation === null || (int) $conversation->getAttribute('handler_id') === (int) $senderId) {
            return; // ردُّ المسؤول نفسه لا يكتب سطراً
        }

        $sender = User::find($senderId);
        if ($sender === null || ! ($sender->isEmployee() || $sender->isAdmin())) {
            return;
        }

        try {
            Workflow::run(new TakeOverConversation, $conversation, $sender, [
                'by' => $sender->id,
                'via' => $message->getAttribute('role'),
            ]);
        } catch (TransitionDenied) {
            // سبقه ردٌّ آخر منه فصار المسؤول — القفل حسم السباق، ولا سطرَ مكرّر
        }
    }

    /**
     * المسؤول الحاليّ وسجلّ من تولّاها — الأحدث أوّلاً — لملفٍّ واحد.
     *
     * @return array{lawyer: ?string, current: array{id: int, name: string, since: ?string}|null, history: list<array{to: string, from: ?string, at: string, via: ?string}>}
     */
    public static function history(Model $conversation): array
    {
        return self::historiesFor([$conversation])[$conversation->getKey()];
    }

    /**
     * السجلّ لقائمة ملفّاتٍ من نوعٍ واحد **باستعلامَين** لا باستعلامَين لكلّ ملفّ — شاشة التنفيذ تعرض
     * الملفّات كلّها في حمولةٍ واحدة.
     *
     * @param  iterable<Model>  $conversations
     * @return array<int|string, array{lawyer: ?string, current: array{id: int, name: string, since: ?string}|null, history: list<array{to: string, from: ?string, at: string, via: ?string}>}>
     */
    public static function historiesFor(iterable $conversations): array
    {
        $conversations = collect($conversations);
        if ($conversations->isEmpty()) {
            return [];
        }

        $rows = JourneyTransition::where('entity_type', class_basename($conversations->first()))
            ->whereIn('entity_id', $conversations->map->getKey()->all())
            ->where('transition', TakeOverConversation::NAME)
            ->latest('id')
            ->get(['entity_id', 'payload', 'actor_id', 'created_at'])
            ->groupBy('entity_id');

        $handlers = User::whereIn('id', $conversations->map(fn (Model $c) => $c->getAttribute('handler_id'))->filter()->unique()->all())
            ->pluck('name', 'id');

        /*
         * **المحامي المُسند سطرٌ مستقلّ في البطاقة** (قرار المالك 2026-09-27). «المسؤول الآن» يقرأ مسؤول
         * المحادثة (موظّفٌ أو مديرٌ ردّ على العميل) والمحامي خارجه عمداً — فكانت قضيّةٌ لها محامٍ ولم يردّ
         * عليها موظّفٌ بعد تقول «لم يتولّها أحدٌ بعد». البطاقة للطاقم وحده، فالاسم حقيقيّ.
         */
        $lawyers = User::whereIn('id', $conversations->map(fn (Model $c) => $c->getAttribute('assigned_lawyer_id'))->filter()->unique()->all())
            ->pluck('name', 'id');

        $when = fn ($at) => $at?->locale('ar')->translatedFormat('l j F · h:i A');

        return $conversations->mapWithKeys(function (Model $c) use ($rows, $handlers, $lawyers, $when) {
            $mine = $rows->get($c->getKey(), collect());
            $handlerId = $c->getAttribute('handler_id');

            $lawyerId = $c->getAttribute('assigned_lawyer_id');

            return [$c->getKey() => [
                'lawyer' => $lawyerId !== null && $lawyers->has($lawyerId) ? (string) $lawyers[$lawyerId] : null,
                'current' => $handlerId === null || ! $handlers->has($handlerId) ? null : [
                    'id' => (int) $handlerId,
                    'name' => (string) $handlers[$handlerId],
                    'since' => $when($mine->first(fn (JourneyTransition $r) => (int) $r->actor_id === (int) $handlerId)?->created_at),
                ],
                'history' => $mine->map(fn (JourneyTransition $r) => [
                    'to' => (string) ($r->payload['to_name'] ?? '—'),
                    'from' => $r->payload['from_name'] ?? null,
                    'at' => (string) $when($r->created_at),
                    'via' => $r->payload['via'] ?? null,
                ])->values()->all(),
            ]];
        })->all();
    }
}
