<?php

namespace App\Jobs;

use App\Events\CaseStatusBroadcast;
use App\Models\LegalCase;
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
            // **محجوبة عن العميل حتى يعتمدها محامٍ.** لائحة الدعوى وثيقةٌ قضائيّة
            // رسميّة، وقد تحمل وسم «لا سند نظاميّ مُتحقَّق» حين يخفق `LegalClaims`
            // في مطابقة ادّعاءاتها — وكانت تصل صاحب القضية قبل أن يقرأها أحد.
            // يُطلقها `AiReviewOutcome::releaseCasePleading` عند القبول أو التعديل.
            'withheld_at' => now(),
        ]);

        // والمحامي يُنبَّه أن مسودّةً تنتظره — وإلّا بقيت محجوبةً إلى الأبد بلا مَن يعلم
        if ($this->case->assigned_lawyer_id !== null) {
            Notify::send(
                $this->case->assigned_lawyer_id,
                'doc',
                't-amber',
                "أُعدّت مسودّة لائحة الدعوى في القضية ({$this->case->number}) وهي محجوبة عن العميل "
                .'بانتظار مراجعتك في محرّر اللائحة بصفحة القضية.'
            );
        }

        // **إشارةٌ لشاشة المكتب أنّ المسودّة جهزت.** المحجوب لا يُبثّ (القناة يسمعها العميل)،
        // والتوليد بالطابور — فكان المحرّر لا يعلم، ويراها المحامي فقط بإعادة التحميل.
        // الحالة وحدها في الحمولة (`CaseStatusBroadcast::broadcastWith`) — لا نصّ.
        Live::push(new CaseStatusBroadcast($this->case->fresh()));
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
