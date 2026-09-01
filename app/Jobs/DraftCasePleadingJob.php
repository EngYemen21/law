<?php

namespace App\Jobs;

use App\Models\LegalCase;
use App\Services\Ai\AiQueue;
use App\Services\Ai\AiRunLogger;
use App\Services\LegalAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DraftCasePleadingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** معرّف التعليمة — منه يُشتقّ الطابور (P6): الفصل بالحساسيّة لا بالحجم. */
    private const PROMPT_ID = 'case.pleading';

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
        public LegalCase $case
    ) {
        $this->routeToAiQueue();
    }

    public function handle(LegalAiService $ai): void
    {
        $result = $ai->draftPleadingResult($this->case);
        $draft = $result['draft'];
        $meta = $result['meta'];

        // قيدٌ في سجلّ القرارات — مسودّة اللائحة كانت المخرج القانونيّ الوحيد الذي
        // يُنتَج بلا أثر: لا كلفة ولا نموذج ولا مراجعة مطلوبة. وهي أخطرها أثراً.

        AiRunLogger::log('case.pleading', $result['source'], $meta, $this->case, (string) $this->case->number);

        $this->case->messages()->create([
            'who' => 'ai',
            'name' => 'المساعد القانوني',
            'role' => 'مسودة اللائحة',
            'body' => '<div class="draft" style="white-space:pre-line">'.e($draft).'</div>',
            'time_label' => $this->clock(),
        ]);
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
