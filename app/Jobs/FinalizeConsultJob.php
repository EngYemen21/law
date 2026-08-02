<?php

namespace App\Jobs;

use App\Events\ConsultStatusBroadcast;
use App\Models\Consult;
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

    public function __construct(
        public Consult $consult,
        public string $notes
    ) {}

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
