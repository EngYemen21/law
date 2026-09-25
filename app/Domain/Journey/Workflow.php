<?php

namespace App\Domain\Journey;

use App\Events\Journey\TransitionCompleted;
use App\Models\JourneyTransition;
use App\Models\User;
use App\Support\Live;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * **الكاتب الوحيد لحالات الرحلة.**
 *
 * يُكتب `tickets.status` اليوم في ٢٣ موضعاً عبر ١٢ ملفّاً، و`consults.status` في ١٨ —
 * وكلُّ كاتبٍ يقرّر حارسه بنفسه. المحرّك يجعل الترتيب واحداً لكلّ انتقال:
 *
 *   قفل الصفّ ← الحالة المصدر ← الفاعل (403) ← الملفّ (422) ← الكتابة ← السجلّ ← الأحداث بعد الالتزام
 *
 * والأحداث بعد الالتزام لا داخله: إشعارٌ أو بريدٌ أُرسل لانتقالٍ تراجع يُخبر العميل بما لم يقع.
 */
final class Workflow
{
    private static int $depth = 0;

    /** هل الكتابة الجارية صادرةٌ من داخل انتقال؟ — يقرؤه `StateWriteGuard`. */
    public static function running(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @template TModel of Model
     *
     * @param  Transition<TModel>  $transition
     * @param  TModel  $entity
     * @param  array<string, mixed>  $payload
     * @return TModel
     */
    public static function run(Transition $transition, Model $entity, ?User $actor = null, array $payload = []): Model
    {
        /** @var list<object> $events */
        $events = [];

        $locked = DB::transaction(function () use ($transition, $entity, $actor, $payload, &$events) {
            /** @var TModel $locked */
            $locked = $entity->newQuery()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();

            $column = $transition->column();
            $from = (string) $locked->getAttribute($column);

            if (! $transition->accepts($from)) {
                throw TransitionDenied::state($from);
            }
            if (($why = $transition->deny($locked, $actor)) !== null) {
                throw TransitionDenied::forbidden($why);
            }
            if (($why = $transition->denyRequest($locked, $actor, $payload)) !== null) {
                throw TransitionDenied::forbidden($why);
            }
            if (($why = $transition->guard($locked, $payload)) !== null) {
                throw TransitionDenied::invalid($why);
            }

            $to = $transition->to($locked, $payload);

            self::$depth++;
            try {
                $transition->apply($locked, $actor, $payload);
                $locked->setAttribute($column, $to);
                $locked->save();

                JourneyTransition::create([
                    'entity_type' => class_basename($locked),
                    'entity_id' => $locked->getKey(),
                    'entity_ref' => self::refOf($locked),
                    'transition' => $transition->name(),
                    'from_state' => $from,
                    'to_state' => $to,
                    'actor_id' => $actor?->id,
                    'reason' => isset($payload['reason']) && is_string($payload['reason']) ? $payload['reason'] : null,
                    'payload' => $transition->record($payload) ?: null,
                ]);
            } finally {
                self::$depth--;
            }

            $events = $transition->events($locked, $from, $actor, $payload);
            $events[] = new TransitionCompleted($transition->name(), $locked, $from, $to, $actor?->id);

            return $locked;
        });

        DB::afterCommit(fn () => self::dispatch($events));

        $entity->setRawAttributes($locked->getAttributes(), true);

        return $entity;
    }

    /**
     * **فتح كيانٍ بحالته الأولى** — الإنشاء ليس انتقالاً (لا حالةَ قبله يُفحص قبولها)، لكنّه
     * أوّل سطرٍ في رحلة الكيان.
     *
     * كانت مواضع الإنشاء تكتب الحالة الأولى خارج المحرّك، فيراها `StateWriteGuard` كتابةً
     * مجهولة، ولا يبدأ سجلُّ الانتقالات إلّا من الانتقال الثاني. هنا يُنشأ الكيان داخل المحرّك،
     * ويُسجَّل سطرُ فتحه (`from_state = null`) بالفاعل والسبب في المعاملة نفسها.
     *
     * الإنشاء نفسه للمنادي (`$create`): حقوله وقيمه الأولى من شأن النطاق لا المحرّك.
     *
     * @template TModel of Model
     *
     * @param  callable(): TModel  $create
     * @param  array<string, mixed>  $payload
     * @return TModel
     */
    public static function open(string $name, callable $create, ?User $actor = null, array $payload = [], string $column = 'status'): Model
    {
        return DB::transaction(function () use ($name, $create, $actor, $payload, $column) {
            self::$depth++;
            try {
                $entity = $create();

                JourneyTransition::create([
                    'entity_type' => class_basename($entity),
                    'entity_id' => $entity->getKey(),
                    'entity_ref' => self::refOf($entity),
                    'transition' => $name,
                    'from_state' => null,
                    'to_state' => (string) $entity->getAttribute($column),
                    'actor_id' => $actor?->id,
                    'reason' => isset($payload['reason']) && is_string($payload['reason']) ? $payload['reason'] : null,
                    'payload' => $payload ?: null,
                ]);
            } finally {
                self::$depth--;
            }

            return $entity;
        });
    }

    /**
     * الانتقالات المتاحة الآن لهذا الفاعل — تُرسَل للواجهة فتعرض الأزرار المسموحة وحدها.
     *
     * @param  iterable<Transition<Model>>  $transitions
     * @return list<string>
     */
    public static function allowed(Model $entity, ?User $actor, iterable $transitions): array
    {
        $names = [];
        foreach ($transitions as $transition) {
            $current = (string) $entity->getAttribute($transition->column());
            if ($transition->accepts($current)
                && $transition->deny($entity, $actor) === null
                && $transition->denyRequest($entity, $actor, []) === null
                && $transition->guard($entity, []) === null) {
                $names[] = $transition->name();
            }
        }

        return $names;
    }

    /**
     * إطلاق أحداث الانتقال بعد الحفظ — والبثّ اللحظيّ منها عبر `Live::push` لا `event()`.
     *
     * أحداث البثّ من نوع ShouldBroadcastNow: `event()` يرسلها شبكيّاً في الطلب نفسه ويرمي إن
     * تعذّر Reverb. والرمي هنا يقع بعد أن حُفظت الحالة، فيُجهض ما بعد الانتقال عند المنادي
     * (إطلاق مهمّة ختم الجلسة من خطّاف Zoom، وإغلاق الغرفة، والرسالة للعميل) ويعيد 500 على تغييرٍ
     * تمّ فعلاً. البثّ تحسينٌ للتجربة لا مصدرٌ للحقيقة، و`Live` يبتلع فشله بقاطع دائرة.
     *
     * @param  list<object>  $events
     */
    private static function dispatch(array $events): void
    {
        foreach ($events as $event) {
            if ($event instanceof ShouldBroadcast) {
                Live::push($event);
            } else {
                event($event);
            }
        }
    }

    private static function refOf(Model $entity): ?string
    {
        foreach (['number', 'ref', 'ext_id'] as $key) {
            $value = $entity->getAttribute($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
