<?php

namespace App\Jobs;

use App\Events\ConsultStatusBroadcast;
use App\Models\Consult;
use App\Services\Ai\AiQueue;
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

        $summary = $ai->consultSummary($consult, $this->notes);
        $consult->update([
            'summary' => $summary,
            'decisions' => $ai->extractDecisions($summary),
        ]);
        Live::push(new ConsultStatusBroadcast($consult));

        Notify::send($consult->user_id, 'doc', 't-green', "انتهت جلسة استشارتك ({$consult->ref}) — ملخص الاستشارة متاح الآن في «استشاراتي».");
    }
}
