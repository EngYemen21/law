<?php

namespace App\Jobs;

use App\Events\ConsultStatusBroadcast;
use App\Models\Consult;
use App\Services\Ai\AiQueue;
use App\Services\Ai\AiRunLogger;
use App\Services\LegalAiService;
use App\Support\Live;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * **القرارات تتبع النصّ المحرَّر** (ع٢٣).
 *
 * كانت `decisions` تُستخرج مرّةً واحدة من مخرج النموذج (`FinalizeConsultJob`)، ثمّ يحرّر
 * المحامي الملخّص فيبقى تحته قراراتُ نصٍّ لم يعد موجوداً — ويقرؤها العميل بعد الاعتماد
 * التزاماتٍ استُنبطت من رأيٍ غيّره محاميه. `saveSummary` يُفرغها ويُطلق هذه المهمّة.
 *
 * لا تكتب إن تغيّر النصّ ثانيةً (تحريرٌ أحدث أطلق مهمّته) أو اعتُمد الملخّص (الاعتماد نهائيّ).
 */
class ExtractConsultDecisionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const PROMPT_ID = 'meeting.decisions';

    public function __construct(
        public Consult $consult,
        public string $text,
    ) {
        $this->onQueue(AiQueue::resolve(self::PROMPT_ID));
    }

    public function handle(LegalAiService $ai): void
    {
        $consult = $this->consult->fresh();
        if ($consult === null || $consult->summaryApproved() || (string) $consult->summary !== $this->text) {
            return;
        }

        $result = $ai->extractDecisionsResult($this->text);
        if (! $result['called']) {
            return;
        }

        AiRunLogger::log(self::PROMPT_ID, $result['source'], $result['meta'], $consult, (string) $consult->ref);

        // إعادة الفحص بعد النداء البطيء: اعتمادٌ أو تحريرٌ وقع أثناءه يسبق هذه الكتابة
        $current = $consult->fresh();
        if ($current === null || $current->summaryApproved() || (string) $current->summary !== $this->text) {
            return;
        }

        $current->update(['decisions' => $result['decisions']]);
        Live::push(new ConsultStatusBroadcast($current));
    }
}
