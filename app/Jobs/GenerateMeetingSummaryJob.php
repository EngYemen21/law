<?php

namespace App\Jobs;

use App\Events\MeetingStatusBroadcast;
use App\Models\Meeting;
use App\Services\Ai\AiQueue;
use App\Services\LegalAiService;
use App\Support\Live;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateMeetingSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** معرّف التعليمة — منه يُشتقّ الطابور (P6): الفصل بالحساسيّة لا بالحجم. */
    private const PROMPT_ID = 'meeting.summary';

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
        public Meeting $meeting,
        public string $notes
    ) {
        $this->routeToAiQueue();
    }

    public function handle(LegalAiService $ai): void
    {
        if (filled($this->meeting->summary) && filled($this->meeting->minutes)) {
            return;
        }

        $out = $ai->meetingSummary($this->meeting->fresh(), $this->notes);

        // لا قالب وهمي: بلا محتوى فعلي تعود الدالّة بحقول فارغة — لا يُكتب شيء،
        // وتبقى الحقول خاوية حتى يصل ملخص Zoom الحقيقي أو يُدوَّن المحضر يدوياً.
        $summary = $this->meeting->summary ?: ($out['summary'] ?? null);
        $minutes = $this->meeting->minutes ?: ($out['minutes'] ?? null);
        if ($summary === null && $minutes === null) {
            return;
        }

        $this->meeting->update([
            'summary' => $summary,
            'minutes' => $minutes,
            'decisions' => $out['decisions'] ?? [],
            'has_summary' => filled($summary),
            'has_minutes' => filled($minutes),
        ]);
        Live::push(new MeetingStatusBroadcast($this->meeting));
    }
}
