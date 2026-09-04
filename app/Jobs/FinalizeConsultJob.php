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

        // **إشعار العميل بختم الجلسة لا يتبع نجاح التوليد.** انتهاء جلسته واقعةٌ
        // تخصّه سواء كُتب الملخّص أم انتظر تدوين المستشار، فكان حصرُه في مسار
        // التوليد يعني أن جلسةً انتهت بلا تدوين تمرّ عليه صامتةً تماماً.
        $firstFinalize = $consult->session_finalized_at === null;

        if ($firstFinalize) {
            $consult->update(['session_finalized_at' => now()]);

            // **لا يُقال «متاح الآن»** لمخرجٍ لم يعتمده أحد. الإشعار بالإتاحة في
            // `AiReviewOutcome` يتبع اعتماد المحامي؛ وهذا إشعارٌ بانتهاء الجلسة وحده.
            Notify::send($consult->user_id, 'doc', 't-blue',
                "انتهت جلسة استشارتك ({$consult->ref}) — يُعدّ المستشار ملخّصها، وسيصلك إشعار فور اعتماده.");
        }

        // بلا مادّة: لا نداء ⇒ **لا قيد** (قيدٌ بلا نداء يُفسد إحصاء الكلفة والتغطية)،
        // ولا كتابة في `summary` — النصّ الذي يصل العميل لا يُملأ من عنوان موضوعه.
        // والمحامي يُنبَّه ليدوّن؛ فبعد التدوين تُستدعى هذه الوظيفة ثانيةً فتولّد.
        if ($result['called'] === false) {
            $this->promptForNotes($consult, $firstFinalize);

            return;
        }

        $summary = (string) $result['summary'];

        // القيد **قبل** الكتابة والإشعار: ملخّص الاستشارة رأيٌ قانونيّ مصنَّف `high`
        // ويصل العميل، وكان يُنتَج بلا أثرٍ واحد — ولا يبلغ صندوق المراجعة أصلاً.
        AiRunLogger::log('consult.summary', $result['source'], $result['meta'], $consult, (string) $consult->ref);

        $decisions = $ai->extractDecisionsResult($summary);
        if ($decisions['called']) {
            AiRunLogger::log('meeting.decisions', $decisions['source'], $decisions['meta'], $consult, (string) $consult->ref);
        }

        $consult->update([
            'summary' => $summary,
            // **عمود الملخّص وحده.** `ai_source` عمودٌ يكتبه `consult.analyze` أيضاً،
            // فكان تعثّرُ الملخّص يدهس مصدر تحليلٍ سابق **نجح**، فتعنون الواجهة
            // «تعذّر التحليل الذكيّ» لتحليلٍ لم يتعثّر. مصدران ⇒ عمودان.
            'summary_ai_source' => $result['source']->value,
            'decisions' => $decisions['decisions'],
        ]);
        Live::push(new ConsultStatusBroadcast($consult));
    }

    /**
     * لا مادّة ⇒ تنبيه المحامي ليدوّن، وقيدُ تدقيق يشهد أن التوليد لم يقع ولماذا.
     *
     * `$first` يحرس التكرار: الجلسة تُختَم من مسارين — زرّ «إنهاء» وويبهوك Zoom — وقد
     * يصلان معاً، وثلاثة تنبيهات عن جلسةٍ واحدة تُقرأ ثلاث جلسات فيُهمَل التنبيه كضجيج.
     *
     * والعميل لا يُقال له إن التدوين ناقص: ذاك شأنُ المكتب، وإخبارُه بعطلٍ تشغيليّ لا
     * يملك له فعلاً يُقلقه بلا فائدة. يُقال له إن الجلسة انتهت وإن المستشار يُعدّ الملخّص
     * — وهو صادقٌ في الحالين.
     */
    private function promptForNotes(Consult $consult, bool $first): void
    {
        $consult->logAudit('النظام', 'ملخص الجلسة', '—', 'لم يُولَّد: لا ملاحظات مدوَّنة');
        $consult->save();

        $lawyerId = $consult->assigned_lawyer_id;
        if (! $first || $lawyerId === null) {
            return;
        }

        Notify::send(
            $lawyerId,
            'doc',
            't-amber',
            "انتهت جلسة الاستشارة ({$consult->ref}) بلا ملاحظات مدوَّنة، فلم يُعدّ لها ملخّص. "
            .'دوّن ما دار في الجلسة من صفحة الاستشارة ليُبنى الملخّص عليه.'
        );
    }
}
