<?php

namespace App\Jobs;

use App\Models\Consult;
use App\Models\Meeting;
use App\Support\Notify;
use App\Support\RecordingArchive;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * بناء أرشيف تسجيل الجلسة (ZIP) خارج دورة الطلب.
 *
 * لماذا: جلب الملف من سحابة Zoom يستغرق دقائق لملف فيديو، بينما مهلة nginx الافتراضية
 * 60ث ⇒ 504 حتمي لو بُني داخل طلب HTTP. الطابور يبنيه ويحفظه محلياً، ثم يُشعَر طالبه
 * فينزل من القرص فوراً في النقرة التالية (والمجدول يبنيه سلفاً بعد انتهاء الجلسة).
 */
class BuildRecordingArchive implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** نافذة التفرّد: لا تُصفّ مهمة ثانية لنفس الأرشيف ما دامت الأولى قائمة. */
    public int $uniqueFor = 3600;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(
        private readonly string $modelClass,
        private readonly int $modelId,
        private readonly string $type,
        private readonly ?int $notifyUserId = null,
    ) {}

    /** يجدول بناء الأرشيف لنموذج (Meeting|Consult)؛ المُخطَر افتراضاً هو الطالب الحالي. */
    public static function for(Model $model, string $type = 'video', ?int $notifyUserId = null): void
    {
        $job = new self($model::class, (int) $model->getKey(), $type, $notifyUserId ?? auth()->id());

        // بلا عامل طابور (QUEUE_CONNECTION=sync) تُنفَّذ المهمة داخل الطلب نفسه، وهذا
        // بالضبط ما بُني الطابور لتفاديه: جلب فيديو من Zoom يتجاوز مهلة nginx ⇒ 504.
        // نؤجّلها إلى ما بعد إرسال الاستجابة كي يصل الردّ للمستخدم، ونسجّل تحذيراً
        // لأن هذا وضع تطوير لا إنتاج — الإنتاج يلزمه عامل حقيقي (راجع deploy.sh).
        if (self::isSynchronous()) {
            Log::warning('BuildRecordingArchive: لا عامل طابور (QUEUE_CONNECTION=sync) — سيُبنى الأرشيف بعد الاستجابة داخل عملية الويب.', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'type' => $type,
            ]);

            dispatch($job)->afterResponse();

            return;
        }

        dispatch($job);
    }

    /** طابور صوريّ: يُنفَّذ داخل الطلب. الاختبارات مستثناة — تعتمد التنفيذ الفوريّ عمداً. */
    private static function isSynchronous(): bool
    {
        return config('queue.default') === 'sync'
            && ! app()->runningInConsole()
            && ! app()->runningUnitTests();
    }

    /** مفتاح التفرّد: أرشيف واحد لكل (نموذج، سجلّ، نوع). */
    public function uniqueId(): string
    {
        return $this->modelClass.':'.$this->modelId.':'.$this->type;
    }

    /** مفتاح كبح إعادة المحاولة بعد فشل — يمنع إغراق الطابور من المجدول كل 15 دقيقة. */
    private function cooldownKey(): string
    {
        return 'recording-archive-failed:'.$this->uniqueId();
    }

    /** هل فشلت محاولة قريبة لهذا الأرشيف؟ (يُستعمل من المجدول قبل الجدولة) */
    public static function recentlyFailed(Model $model, string $type): bool
    {
        return Cache::has('recording-archive-failed:'.$model::class.':'.$model->getKey().':'.$type);
    }

    public function handle(): void
    {
        /** @var Meeting|Consult|null $model */
        $model = $this->modelClass::find($this->modelId);
        if ($model === null) {
            return;
        }

        if (RecordingArchive::isReady($model, $this->type)) {
            $this->notifyReady($model);

            return;
        }

        $path = RecordingArchive::build($model, $this->type);
        if ($path === null) {
            Log::warning('BuildRecordingArchive: تعذّر بناء الأرشيف', [
                'model' => $this->modelClass, 'id' => $this->modelId, 'type' => $this->type,
            ]);
            // كبح 6 ساعات: المجدول يعمل كل 15 دقيقة، وبلا هذا يُعاد صفّ الأرشيف الفاشل 96 مرة يومياً
            Cache::put($this->cooldownKey(), true, now()->addHours(6));

            if ($this->notifyUserId) {
                Notify::send($this->notifyUserId, 'info', 't-amber', 'تعذّر تحضير ملف الجلسة — قد لا يكون التسجيل جاهزاً لدى Zoom بعد.');
            }

            return;
        }

        $this->notifyReady($model);
    }

    private function notifyReady(Model $model): void
    {
        if (! $this->notifyUserId) {
            return;
        }

        $ref = (string) ($model->ref ?: $model->getKey());
        $label = $this->type === 'audio' ? 'صوت' : 'تسجيل';
        Notify::send($this->notifyUserId, 'video', 't-green', "ملف {$label} الجلسة {$ref} جاهز للتنزيل الآن.");
    }
}
