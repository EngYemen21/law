<?php

namespace App\Jobs;

use App\Enums\AiSource;
use App\Models\Execution;
use App\Models\ExecutionDocument;
use App\Services\Ai\AiQueue;
use App\Services\Ai\AiRunLogger;
use App\Services\LegalAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * تحليل ذكي لمستند تنفيذ مرفَق بالخلفية: تصنيف + ملخّص، ثم إرسال ملخّص لمحادثة التنفيذ.
 * لا يُغيَّر status (يبقى ضمن دورة حياة الاعتماد: مرفوع → مقبول/مرفوض) — التحليل إثرائي فقط.
 */
class AnalyzeExecutionDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** معرّف التعليمة — منه يُشتقّ الطابور (P6): الفصل بالحساسيّة لا بالحجم. */
    private const PROMPT_ID = 'document.analyze';

    /**
     * الطابور يُحسم عند الإنشاء. `resolve` تُعيد `null` ما لم يُفعَّل
     * AI_SEPARATE_QUEUES، فيبقى السلوك على الطابور الافتراضيّ كما هو —
     * تفعيلٌ قبل تحديث أمر العامل يوقف معالجة الذكاء صامتةً.
     */
    private function routeToAiQueue(): void
    {
        $this->onQueue(AiQueue::resolve(self::PROMPT_ID));
    }

    public function __construct(
        public Execution $execution,
        public ExecutionDocument $doc
    ) {
        $this->routeToAiQueue();
    }

    // نافذة إعادة المحاولة: 24 ساعة (تتجاوز التجدّد اليومي لحصّة المزوّد) — شفاء ذاتي
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(LegalAiService $ai): void
    {
        $doc = $this->doc->fresh();
        if ($doc === null) {
            return;
        }

        // قاطع الدائرة: المزوّد مهدّأ الآن (نفاد حصّة/ازدحام) → أعد المحاولة لاحقاً بلا وسم دائم
        if ($ai->isConfigured() && ! $ai->available()) {
            $this->release(now()->addMinutes(30));

            return;
        }

        $analysis = $ai->analyzeExecutionDocument($this->execution, $doc);
        if ($analysis === null) {
            if ($ai->isConfigured() && ! $ai->available()) {
                $this->release(now()->addMinutes(30));
            }

            return;
        }

        // يُسجَّل كأيّ مخرج ذكاء — كان يجري بلا قيدٍ في `ai_runs`، فيُكتب حكمُه في
        // ملفّ التنفيذ ولا يظهر في الكلفة ولا التغطية ولا صندوق المراجعة.
        AiRunLogger::log(
            'document.analyze',
            AiSource::AiSuccess,
            is_array($analysis['meta'] ?? null) ? $analysis['meta'] : [],
            $this->execution,
            (string) $this->execution->number,
        );

        $doc->update([
            'doc_type' => $analysis['doc_type'],
            'summary' => $analysis['summary'],
        ]);

        // ملخّص التحليل كرسالة مرئية في محادثة التنفيذ (تُبثّ لحظياً عبر ExecutionMessage::booted)
        $this->execution->messages()->create([
            'who' => 'ai',
            'name' => 'الفريق القانوني',
            'role' => 'تحليل المستند',
            'body' => '<p>تم فحص المستند «'.e($doc->label).'» وتلخيص محتواه.</p>'
                .'<div class="doc-list" style="flex-direction:column;align-items:stretch">'
                .'<span class="doc-chip">📄 النوع: '.e((string) $doc->doc_type).'</span>'
                .(! empty($doc->summary) ? '<span class="doc-chip">📝 '.e((string) $doc->summary).'</span>' : '')
                .'</div>',
            'time_label' => now()->format('h:i').' '.(now()->hour < 12 ? 'ص' : 'م'),
        ]);
    }
}
