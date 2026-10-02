<?php

namespace App\Jobs;

use App\Models\LegalCase;
use App\Services\Ai\AiQueue;
use App\Services\Ai\AiRunLogger;
use App\Services\LegalAiService;
use App\Support\AiClientVoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateCaseReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** معرّف التعليمة — منه يُشتقّ الطابور (P6): الفصل بالحساسيّة لا بالحجم. */
    private const PROMPT_ID = 'chat.reply';

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
        public string $body
    ) {
        $this->routeToAiQueue();
    }

    public function handle(LegalAiService $ai): void
    {
        $case = $this->case->fresh();
        if (! $case || ! $case->isActive()) {
            return;
        }

        // منع AI من الرد على الموظف أو المحامي أو الإدارة — الرد الآلي للعميل فقط
        $lastMsg = $case->messages()->reorder('id', 'desc')->first();
        if ($lastMsg && $lastMsg->who !== 'client') {
            return;
        }

        // **ولا ردَّ آليّاً بعد تدخّل إنسانٍ من المكتب** (قرار المالك 2026-10-02) — البشر يتولّون الحوار
        // والذكاء يبقى للتحليل (فحص المستندات وما إليه، ملاحظاتٍ للطاقم إن لزم — `AiClientVoice`).
        if (AiClientVoice::humanIntervened($case)) {
            AiClientVoice::handOff($case, "القضية {$case->number}");

            return;
        }

        $replyRun = $ai->caseReplyResult($this->case, $this->body);
        AiRunLogger::log('chat.reply', $replyRun['source'], $replyRun['meta'], $this->case, (string) $this->case->number);

        $aiText = $replyRun['text']
            ?? 'تم استلام رسالتك بخصوص القضية، وسيوافيك المختص بالرد في أقرب وقت.';

        $this->case->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => LegalAiService::AGENT_ROLE,
            'body' => nl2br(e($aiText)),
            'time_label' => $this->clock(),
        ]);
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
