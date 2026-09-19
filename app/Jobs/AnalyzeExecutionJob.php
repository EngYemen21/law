<?php

namespace App\Jobs;

use App\Enums\AiSource;
use App\Models\AiRun;
use App\Models\Execution;
use App\Services\Ai\AiQueue;
use App\Services\Ai\AiRunLogger;
use App\Services\LegalAiService;
use App\Support\ExecService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * التحليل الذكيّ لطلب التنفيذ (تدفّق البطاقات) بالخلفية — نظير GenerateExecutionReplyJob.
 * ينادي LegalAiService::analyzeExecution ثمّ يطبّق النتيجة عبر ExecService::applyAnalysis.
 */
class AnalyzeExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** معرّف التعليمة — منه يُشتقّ الطابور (P6): الفصل بالحساسيّة لا بالحجم. */
    private const PROMPT_ID = 'execution.analyze';

    /** سقف المحاولات قبل تسليم الملفّ للفحص اليدويّ — يُقاس على العمود لا على محاولات الطابور. */
    public const MAX_ATTEMPTS = 5;

    /** تهدئةٌ بين مخرجٍ أُنتج وإعادةٍ تُسمح — دونها إعادةُ الطابور الفوريّة تُكرّر المخرج. */
    public const RETRY_COOLDOWN_MINUTES = 30;

    /**
     * **التعذّر يُعاد لا يُملأ بقالب** (قرار المالك 2026-09-12).
     *
     * مزوّدا الذكاء يردّان 429 عند بلوغ الحدّ فيدخلان تهدئة (قيسَ اليوم: Gemini ٣٠ دقيقة،
     * GLM ٦ ساعات)، فالمحاولة الفوريّة تُهدر. محاولتان متباعدتان هنا، ثمّ يلتقط الباقي
     * الأمر المجدول `exec:retry-study`. والمهلة أوسع من مهلة النداء نفسه: كان العامل
     * يقتل المهمّة في منتصف النداء فتنتهي في `failed_jobs` بلا أثرٍ على الملفّ.
     */
    public int $tries = 2;

    public int $timeout = 180;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [300, 1800]; // ٥ دقائق ثمّ نصف ساعة
    }

    /**
     * الطابور يُحسم عند الإنشاء. `resolve` تُعيد `null` ما لم يُفعَّل
     * AI_SEPARATE_QUEUES، فيبقى السلوك على الطابور الافتراضيّ كما هو —
     * تفعيلٌ قبل تحديث أمر العامل يوقف معالجة الذكاء صامتةً.
     */
    private function routeToAiQueue(): void
    {
        $this->onQueue(AiQueue::resolve(self::PROMPT_ID));
    }

    public function __construct(public Execution $execution)
    {
        $this->routeToAiQueue();
    }

    public function handle(LegalAiService $ai): void
    {
        $exec = $this->execution->fresh(['documents']);

        // **لا تُعاد دراسةٌ نجحت، وتُعاد كلّ ما عداها.** `ai_done` صادقةٌ للتحليل الفعليّ
        // وحده (الاحتياطيّ لا يدّعي اكتمالاً)، فهي حارس التكرار الحقيقيّ. والملفّ المنتهي
        // مستثنى: دراسةٌ لملفٍّ أُقفل كلفةٌ بلا قرارٍ يُبنى عليها. والسقف يحمي من الدوران.
        //
        // **والمرفوض مثله** — و`isClosed` لا يلتقطه: `reject` يكتب القرار ولا ينقل المرحلة
        // (المرفوض ليس مؤرشفاً)، فكان الطابور يعيد دراسته ويصل العميلَ إشعار «طلبك قيد
        // الدراسة» بعد أن بلغه بريدُ الرفض. والخروج صامتٌ لا استثناء: هذا مسار طابورٍ لا نداء.
        if ($exec === null || $exec->ai_done || $exec->isClosed() || $exec->decision === 'مرفوض'
            || (int) $exec->ai_attempts >= self::MAX_ATTEMPTS) {
            return;
        }

        // **منعُ التكرار زمنيّ لا مطلق.** `AiRun::alreadyRan` وحده كان يمنع كلّ إعادةٍ بعد
        // أوّل تعذّر (القيد يُكتب للاحتياطيّ أيضاً) فينقض قرار المالك «تُعاد جدولتها»؛
        // وإسقاطه وحده يجعل إعادةَ الطابور الفوريّة تُنتج مخرجاً ثانياً — قيداً وملاحظةً
        // وموجةَ تنبيه. فالمخرجُ المُنتَج لا يُعاد إلا بعد تهدئة، والإعادة المجدولة
        // (`exec:retry-study`) تأتي بعدها. و`null` في الطابع صفٌّ قديمٌ سبق أن أنتج
        // مخرجاً: يبقى محروساً كما كان.
        if (AiRun::alreadyRan('execution', $exec)
            && ! $exec->ai_attempted_at?->lte(now()->subMinutes(self::RETRY_COOLDOWN_MINUTES))) {
            return;
        }

        // المحاولة تُحسب قبل النداء لا بعده: نداءٌ يُقتل في منتصفه لا يعود ليحسب نفسه
        $exec->update(['ai_attempts' => (int) $exec->ai_attempts + 1, 'ai_attempted_at' => now()]);

        // تحليل وتصنيف كل مستند مرفق بالذكاء الاصطناعي
        foreach ($exec->documents as $doc) {
            if (empty($doc->summary)) {
                $docAnalysis = $ai->analyzeExecutionDocument($exec, $doc);
                if ($docAnalysis) {
                    // **قيدٌ لكلّ مستند.** الحلقة كانت تُنادي النموذج مرّةً لكلّ مرفق
                    // بلا قيدٍ واحد، فطلبٌ بخمسة مستندات = خمسة نداءات لا أثر لها في
                    // الكلفة ولا التغطية — وهو أكبر مصدرٍ منفردٍ للنداءات غير المحسوبة.
                    AiRunLogger::log(
                        'document.analyze',
                        AiSource::AiSuccess,
                        is_array($docAnalysis['meta'] ?? null) ? $docAnalysis['meta'] : [],
                        $exec,
                        (string) $exec->number,
                    );

                    $doc->update([
                        'doc_type' => $docAnalysis['doc_type'],
                        'summary' => $docAnalysis['summary'],
                    ]);
                }
            }
        }

        $result = $ai->analyzeExecution($exec->fresh(['documents']));
        ExecService::applyAnalysis($exec, $result);

        // تعذّرٌ رجع بمخرجٍ فارغ (429/انقطاع) — يُعاد لا يُترك، والملاحظة الصادقة كُتبت في applyAnalysis
        if (($result['source'] ?? null) === AiSource::Fallback->value) {
            ExecService::scheduleStudyRetry($exec->fresh());
        }
    }

    /**
     * ماتت المهمّة (مهلة، أو استنفاد المحاولات) — لا تُترك بصمت: يُسجَّل التعذّر على الملفّ
     * ويُنبَّه المكتب وتُعاد الجدولة. كان الملفّ يبقى «الدراسة قيد الإعداد» أبداً.
     */
    public function failed(?Throwable $e): void
    {
        ExecService::studyUnavailable($this->execution->fresh() ?? $this->execution, (string) $e?->getMessage());
    }
}
