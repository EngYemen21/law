<?php

namespace App\Jobs;

use App\Events\CaseStatusBroadcast;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Services\Ai\AiQueue;
use App\Services\Ai\AiRunLogger;
use App\Services\LegalAiService;
use App\Support\Live;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * تنقيح تصنيف القضية بالذكاء الاصطناعي **بعد** إنشائها.
 *
 * لماذا: كان CaseConversion ينادي classifyCase داخل الطلب قبل فتح المعاملة، فيحبسه
 * **12.3 ثانية مقاسة حيّاً** (مقابل 1.18 للنقرة المرفوضة). ومهلة FPM ثلاثون ثانية، فتعثّر
 * المزوّد يعني 504 وإعادة نقر من المستخدم. القضية تُنشأ الآن فوراً بالتصنيف الاحتياطي
 * الحتمي (LegalAiService::fallbackClassification) وهذه المهمّة تُنقّحه إن أفاد المزوّد.
 *
 * أفضل-جهد بالكامل: فشلها يترك القضية بتصنيف صالح لا ناقص.
 */
class ClassifyConvertedCaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** معرّف التعليمة — منه يُشتقّ الطابور (P6): الفصل بالحساسيّة لا بالحجم. */
    private const PROMPT_ID = 'case.classify';

    /**
     * الطابور يُحسم عند الإنشاء. `resolve` تُعيد `null` ما لم يُفعَّل
     * AI_SEPARATE_QUEUES، فيبقى السلوك على الطابور الافتراضيّ كما هو —
     * تفعيلٌ قبل تحديث أمر العامل يوقف معالجة الذكاء صامتةً.
     */
    private function routeToAiQueue(): void
    {
        $this->onQueue(AiQueue::resolve(self::PROMPT_ID));
    }

    public function __construct(public LegalCase $case)
    {
        $this->routeToAiQueue();
    }

    /** نافذة إعادة المحاولة: يوم — تتجاوز التجدّد اليومي لحصّة المزوّد (نظير GenerateTicketSummaryJob). */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(LegalAiService $ai): void
    {
        $case = $this->case->fresh();
        if ($case === null || $case->ticket === null || ! $case->acceptsReclassification()) {
            return;
        }

        // قاطع الدائرة: المزوّد مهدّأ الآن (نفاد حصّة) ⇒ أعد المحاولة بلا استهلاك نداء
        if ($ai->isConfigured() && ! $ai->available()) {
            $this->release(now()->addMinutes(30));

            return;
        }

        $result = $ai->classifyCaseResult($case->ticket);
        $analysis = $result['classification'];
        $meta = $result['meta'];
        $fallback = LegalAiService::fallbackClassification($case->ticket);

        // القيد **قبل** الخروج المبكر: كانت القضية تُصنَّف بلا أثرٍ واحد في سجلّ
        // القرارات حين يطابق مخرجُ النموذج الاحتياطيَّ — نداءٌ جرى ودُفع ثمنه ولا
        // كلفة محصاة ولا إصدار تعليمة مسجَّل. و«لم يُفِد بجديد» معلومةٌ تقييميّة.

        AiRunLogger::log('case.classify', $result['source'], $meta, $case, (string) $case->number);

        // لم يُفِد المزوّد بجديد — لا تلمس القضية ولا تبثّ تحديثاً بلا محتوى
        if ($analysis === $fallback) {
            return;
        }

        // **مقترحٌ لا حكم.** سياسة `case.classify` «عالية» (`AiPolicyGate`): مراجعةٌ بشريّة
        // إلزاميّة. وكانت هذه الوظيفة تكتب النوع والقسم وتعيد كتابة رسالة «التحليل» التي يراها
        // العميل — فالمخرج يصل صاحبَه والقيدُ يقول «بانتظار المراجعة». يُحفظ هنا ويطبّقه
        // `AiReviewOutcome::releaseCaseClassification` عند القبول أو التعديل.
        $case->update(['ai_classification' => ['type' => $analysis['type'], 'department' => $analysis['department']]]);

        Live::push(new CaseStatusBroadcast($case));

        Log::info('case.classification.proposed', [
            'case' => $case->number, 'type' => $analysis['type'], 'department' => $analysis['department'],
        ]);
    }

    /**
     * نصّ رسالة «تحليل» — مصدر واحد يُنادى من CaseConversion عند الإنشاء ومن هنا عند التنقيح،
     * فلا تتباعد صياغتان لنفس الرسالة.
     *
     * @param  array{type: string, department: string}  $analysis
     */
    public static function analysisBody(Ticket $ticket, array $analysis, bool $refined = false): string
    {
        $details = [];
        if ($ticket->opponent_name) {
            $details[] = "الخصم: {$ticket->opponent_name}";
        }
        if ($ticket->court_name) {
            $details[] = "المحكمة المختصة: {$ticket->court_name}";
        }
        if ($ticket->claim_amount) {
            $details[] = 'المبلغ المطالب به: '.number_format((float) $ticket->claim_amount).' ر.س';
        }
        $extraChips = ! empty($details)
            ? implode('', array_map(fn ($d) => '<span class="doc-chip">'.e($d).'</span>', $details))
            : '';

        // **العنوان يتبع المصدر.** تُنادى هذه من موضعين: `CaseConversion` عند
        // الإنشاء بمخرج `fallbackClassification` (نسخُ نوع التذكرة وقسمها — بلا أي
        // نداء نموذج)، ومن هذه الوظيفة بعد تنقيحٍ حقيقيّ. وكان العنوان «تحليل ذكي»
        // في الحالتين، فيقرأ العميل نسخاً حرفياً تحليلاً. والوظيفة تخرج مبكراً حين
        // يوافق النموذجُ القالبَ، فيبقى العنوان كاذباً بلا تصحيح.
        return '<p>'.($refined ? 'تحليل ذكي للطلب:' : 'بيانات الطلب:').'</p><div class="doc-list">'
            .'<span class="doc-chip">نوع القضية: '.e($analysis['type']).'</span>'
            .'<span class="doc-chip">القسم المختص: '.e($analysis['department']).'</span>'
            .$extraChips.'</div>';
    }
}
