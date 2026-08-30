<?php

namespace App\Jobs;

use App\Models\CaseDocument;
use App\Models\LegalCase;
use App\Services\Ai\AiQueue;
use App\Services\LegalAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * تحليل ذكي لمستند ملف القضية بالخلفية: تلخيص + تصنيف، ثم إرسال ملخّص لمحادثة القضية.
 * لا محتوى مُختلَق: عند تعذّر الفحص يُوسَم المستند «بحاجة لمراجعة يدوية» بلا رسالة.
 */
class AnalyzeCaseDocumentJob implements ShouldQueue
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
        public LegalCase $case,
        public CaseDocument $doc
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

        // قاطع الدائرة: المزوّد مهدّأ الآن (نفاد حصّة/ازدحام) → أعد المحاولة لاحقاً بلا وسم يدوي دائم
        if ($ai->isConfigured() && ! $ai->available()) {
            $this->release(now()->addMinutes(30));

            return;
        }

        $analysis = $ai->analyzeCaseDocument($this->case, $doc);
        if ($analysis === null) {
            // تعذّر مؤقّت طرأ أثناء النداء (تهدئة جديدة)؟ أعد المحاولة بدل الوسم اليدوي الدائم
            if ($ai->isConfigured() && ! $ai->available()) {
                $this->release(now()->addMinutes(30));

                return;
            }
            $doc->update(['status' => 'بحاجة لمراجعة يدوية']);

            return;
        }

        $doc->update([
            'status' => 'محلَّل',
            'doc_type' => $analysis['doc_type'],
            'summary' => $analysis['summary'],
        ]);

        // ملخّص التحليل كرسالة مرئية في محادثة القضية (تُبثّ لحظياً عبر CaseMessage::booted)
        $this->case->messages()->create([
            'who' => 'ai',
            'name' => 'الفريق القانوني',
            'role' => 'تحليل المستند',
            'body' => '<p>تم فحص المستند «'.e($doc->name).'» وتلخيص محتواه.</p>'
                .'<div class="doc-list" style="flex-direction:column;align-items:stretch">'
                .'<span class="doc-chip">📄 النوع: '.e((string) $doc->doc_type).'</span>'
                .(! empty($doc->summary) ? '<span class="doc-chip">📝 '.e((string) $doc->summary).'</span>' : '')
                .'</div>',
            'time_label' => now()->format('h:i').' '.(now()->hour < 12 ? 'ص' : 'م'),
        ]);
    }
}
