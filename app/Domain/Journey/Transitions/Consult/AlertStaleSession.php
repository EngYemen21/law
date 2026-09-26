<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Transition;
use App\Models\Consult;
use App\Models\JourneyTransition;
use Illuminate\Database\Eloquent\Model;

/**
 * **تنبيهٌ بجلسةٍ منسيّة — مرّةً واحدة، ولا تتغيّر الجلسة.** (قرار المالك 2026-09-26)
 *
 * جلسةٌ بدأت ولم يُنهها أحد بعد `session_stale_minutes` **لا تُنهى آليّاً**: قد تكون منعقدةً فعلاً،
 * والختم الآليّ يكتب «منتهية» ويطلب ملخّصاً لما لم يُعرف كيف انتهى. يُنبَّه الطاقم وحده ويُنهيها
 * من يعرف حالها.
 *
 * **انتقالٌ إلى الحالة نفسها** (نمط `AssignExecutionLawyer`): لا يغيّر `session`، لكنّ سطره في
 * سجلّ الرحلة هو **ذاكرة التنبيه** — شبكة الأمان تمرّ كلّ ربع ساعة، والحارس يرفض تنبيهاً ثانياً
 * لأنّ السطر موجود. والفحص تحت قفل الصفّ في `Workflow`، فتمرّتان متزامنتان لا تنبّهان مرّتين.
 * لماذا لا عمود `stale_alerted_at`: عمودٌ في جدولين يُكرّر ما يحفظه السجلّ أصلاً (متى ولماذا)،
 * ويتباعد عنه إن كُتب أحدهما دون الآخر.
 *
 * الإشعار والتدقيق عند المنادي (`sessions:close-stale`) بعد نجاح الانتقال — لا شبكة هنا؛ والمنادي
 * يُنهي الجلسة بعده بانتقال الإنهاء (قرار المالك 2026-09-26 الأخير: تنبيهٌ وإنهاء).
 *
 * @extends Transition<Consult>
 */
final class AlertStaleSession extends Transition
{
    public function name(): string
    {
        return 'consult.stale_alerted';
    }

    public function column(): string
    {
        return 'session';
    }

    public function from(): array
    {
        return [SessionState::Live->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return SessionState::Live->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        return JourneyTransition::happened($entity, $this->name())
            ? 'نُبّه الطاقم بهذه الجلسة من قبل.'
            : null;
    }

    public function record(array $payload): array
    {
        return array_filter([
            'started_at' => $payload['started_at'] ?? null,
            'after_minutes' => $payload['after_minutes'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
