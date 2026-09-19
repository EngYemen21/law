<?php

namespace App\Domain\Journey;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **انتقالٌ واحد مسمّى** — الشرط والكتابة والأثر في مكانٍ واحد.
 *
 * كانت الحرّاس مكتوبةً داخل كلّ متحكّم بـ`abort_if`، فيُسدّ البابُ في `refer` ويبقى مفتوحاً
 * في `analyze` و`reschedule` و`start` (التشخيص الجذريّ في خطّة الرحلة). هنا يُسأل سؤالٌ
 * واحد: «هل يجوز الانتقال من س إلى ص لهذا الفاعل؟» — ويجيب عنه `Workflow` وحده.
 *
 * @template TModel of Model
 */
abstract class Transition
{
    /**
     * يقبل أيّ حالةٍ مصدراً ويترك القرار لـ`guard`. لانتقالاتٍ رفضُها يحتاج رسالةً تشرح
     * سببه (لا «حالة غير مقبولة» عامّة)، ولصفوفٍ قديمة تحمل حالاتٍ مطويّة لا يعرفها التعداد.
     */
    public const ANY = ['*'];

    /** هل الحالة الحاليّة مصدرٌ مقبول؟ */
    final public function accepts(string $current): bool
    {
        $from = $this->from();

        return $from === self::ANY || in_array($current, $from, true);
    }

    /** معرّفٌ ثابت يُسجَّل في `journey_transitions` — مثل `consult.cancel`. */
    abstract public function name(): string;

    /** @return list<string> الحالات التي يجوز الانطلاق منها */
    abstract public function from(): array;

    /**
     * @param  TModel  $entity
     * @param  array<string, mixed>  $payload
     */
    abstract public function to(Model $entity, array $payload): string;

    /** العمود الذي يكتبه الانتقال. */
    public function column(): string
    {
        return 'status';
    }

    /**
     * رفضٌ بسبب الفاعل (⇒ 403). `null` = مسموح.
     *
     * @param  TModel  $entity
     */
    public function deny(Model $entity, ?User $actor): ?string
    {
        return null;
    }

    /**
     * رفضٌ بسبب حال الملفّ (⇒ 422). يُنادى أيضاً بحمولةٍ فارغة من `Workflow::allowed`،
     * فلا يفترض وجود مفاتيحها.
     *
     * @param  TModel  $entity
     * @param  array<string, mixed>  $payload
     */
    public function guard(Model $entity, array $payload): ?string
    {
        return null;
    }

    /**
     * كتاباتٌ مرافقة داخل المعاملة والصفّ مقفول — **لا شبكة ولا إشعار هنا**؛ تلك في الأحداث.
     *
     * @param  TModel  $entity
     * @param  array<string, mixed>  $payload
     */
    public function apply(Model $entity, ?User $actor, array $payload): void {}

    /**
     * أحداثٌ تُطلق **بعد الالتزام**.
     *
     * @param  TModel  $entity
     * @param  array<string, mixed>  $payload
     * @return list<object>
     */
    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        return [];
    }

    /**
     * ما يُحفظ من الحمولة في السجلّ — لا شيء افتراضاً: السجلّ ليس مكاناً لبيانات العميل.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function record(array $payload): array
    {
        return [];
    }
}
