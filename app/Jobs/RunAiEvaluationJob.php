<?php

namespace App\Jobs;

use App\Services\Ai\AiEvaluator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * التقييم الحيّ في الخلفية.
 *
 * عشر حالات = عشرة نداءات متسلسلة للمزوّد؛ تشغيلها داخل طلب ويب يعني انتظاراً
 * يتجاوز مهلة الطلب غالباً. الشاشة تطلق التشغيل وتقرأ حصيلته لاحقاً بدل أن تتجمّد.
 */
class RunAiEvaluationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** نداءات متتالية بطيئة — المهلة لكامل المجموعة لا لنداءٍ واحد. */
    public int $timeout = 900;

    /**
     * لا إعادة محاولة: كل محاولة تستهلك حصّة المزوّد فعلياً، والتقييم قياسٌ
     * يُعاد بقرار لا تلقائياً.
     */
    public int $tries = 1;

    /** @param array<int,string> $tasks */
    public function __construct(
        public array $tasks,
        public ?string $by = null,
    ) {}

    public function handle(): void
    {
        $evaluator = new AiEvaluator;
        $results = $evaluator->run($this->tasks, live: true);

        AiEvaluator::remember($results, true, $evaluator->cost(), $this->by, liveCalls: $evaluator->liveCalls());
    }

    /** الفشل يجب أن يُقرأ في الشاشة لا في السجلّ وحده. */
    public function failed(\Throwable $e): void
    {
        Log::warning('[RunAiEvaluationJob] تعذّر إكمال التقييم الحيّ: '.$e->getMessage());

        AiEvaluator::remember([], true, null, $this->by, failure: 'تعذّر إكمال التشغيل الحيّ — راجع سجلّ الأخطاء.');
    }
}
