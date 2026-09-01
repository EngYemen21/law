<?php

namespace App\Jobs;

use App\Events\ConsultStatusBroadcast;
use App\Models\Consult;
use App\Services\Ai\AiQueue;
use App\Services\Ai\AiRunLogger;
use App\Services\LegalAiService;
use App\Support\Live;
use App\Support\Notify;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * إنهاء الاستشارة في الخلفية: توليد الملخّص + استخراج القرارات (نداءا AI) بعيداً عن طلب الموظف —
 * فلا يُعلَّق «إنهاء الجلسة» حتى ~300ث. الجلسة تُختَم فوراً في الطلب؛ الملخّص يصل لحظياً عند جهوزه.
 */
class FinalizeConsultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** معرّف التعليمة — منه يُشتقّ الطابور (P6): الفصل بالحساسيّة لا بالحجم. */
    private const PROMPT_ID = 'consult.summary';

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
        public Consult $consult,
        public string $notes
    ) {
        $this->routeToAiQueue();
    }

    public function handle(LegalAiService $ai): void
    {
        $consult = $this->consult->fresh();
        if ($consult === null || filled($consult->summary)) {
            return; // أُنجز مسبقاً
        }

        $result = $ai->consultSummaryResult($consult, $this->notes);
        $summary = $result['summary'];

        // القيد **قبل** الكتابة والإشعار: ملخّص الاستشارة رأيٌ قانونيّ مصنَّف `high`
        // ويصل العميل، وكان يُنتَج بلا أثرٍ واحد — ولا يبلغ صندوق المراجعة أصلاً.
        AiRunLogger::log('consult.summary', $result['source'], $result['meta'], $consult, (string) $consult->ref);

        $decisions = $ai->extractDecisionsResult($summary);
        if ($decisions['called']) {
            AiRunLogger::log('meeting.decisions', $decisions['source'], $decisions['meta'], $consult, (string) $consult->ref);
        }

        $consult->update([
            'summary' => $summary,
            'ai_source' => $result['source']->value,
            'decisions' => $decisions['decisions'],
        ]);
        Live::push(new ConsultStatusBroadcast($consult));

        // **لا يُقال «متاح الآن»** لمخرجٍ لم يعتمده أحد. الإشعار بالإتاحة انتقل إلى
        // `AiReviewOutcome` ليتبع اعتماد المحامي؛ وهذا إشعارٌ بانتهاء الجلسة وحده.
        Notify::send($consult->user_id, 'doc', 't-blue', "انتهت جلسة استشارتك ({$consult->ref}) — يُعدّ ملخّصها لاعتماد المستشار، وسيصلك إشعار فور اعتماده.");
    }
}
