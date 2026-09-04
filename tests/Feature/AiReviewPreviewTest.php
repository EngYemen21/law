<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Services\Ai\AiReviewPreview;
use Tests\TestCase;

/**
 * صندوق المراجعة يعرض **النصّ** لا بياناته وحدها.
 *
 * كان يعرض المصدر والنموذج والثقة وتدقيق الحمولة ورمز التعثّر — ولا يعرض ما سيقرؤه
 * الإنسان. فـ«اعتماد» قرارٌ على بياناتٍ وصفيّة عن مخرجٍ لم يُقرأ، ووسمُه «اعتماد
 * بشريّ» أوسعُ ممّا وقع.
 */
class AiReviewPreviewTest extends TestCase
{
    /**
     * **الحارس الأثمن:** كل `task_type` يُكتب فعلاً إمّا يُعايَن أو يُستثنى صراحةً.
     *
     * وقعتُ في هذا بالفعل: طابقتُ على معرّفات التعليمات (`execution.analyze` ·
     * `consult.analyze` · `ticket.triage`)، والمكتوب في `task_type` أسماءٌ قصيرة
     * (`execution` · `consult` · `triage`) لأن المعرّف يُمرَّر في `policyTask`.
     * فخرج الصندوق بلا معاينةٍ واحدة — وهو عطلٌ صامت: لا استثناء ولا خطأ، شاشةٌ
     * تبدو سليمة وقد فقدت الغرض من الدفعة كلّها.
     */
    public function test_every_logged_task_type_is_either_previewed_or_explicitly_excluded(): void
    {
        // ما يُنتج نصّاً محفوظاً يُعايَن
        $previewed = ['consult.summary', 'consult', 'ticket.summary', 'document.analyze',
            'execution', 'case.pleading', 'meeting.decisions'];

        // وما لا مخرج نصّيّ محفوظ له — بسببٍ معلَن لكلٍّ
        $excluded = [
            'najiz.statement' => 'تعود JSON للمتصفّح بلا تخزين',
            'triage' => 'قسمٌ وأولويّة — حقول وصفيّة تُعرض أصلاً',
            'case.classify' => 'نوعٌ وقسم — حقول وصفيّة تُعرض أصلاً',
            'meeting.summary' => 'يُراجَع في شاشة الاجتماع بمحضره وقراراته',
            'chat.reply' => 'الردّ معروضٌ في المحادثة نفسها',
            'assistant.draft' => 'مسودّة المساعد تُعرض في شاشته لحظة توليدها',
        ];

        $logged = [];
        foreach ($this->phpFiles(app_path()) as $file) {
            preg_match_all("/AiRunLogger::log\(\s*'([^']+)'/", (string) file_get_contents($file), $m);
            $logged = array_merge($logged, $m[1]);
        }
        $logged = array_values(array_unique($logged));

        $this->assertNotEmpty($logged, 'المسح يجد قيوداً — وإلّا كان الحارس فارغاً');

        $known = array_merge($previewed, array_keys($excluded));
        $unknown = array_diff($logged, $known);

        $this->assertSame([], array_values($unknown),
            'مهمّةٌ تُقيَّد بلا قرارٍ في المعاينة: '.implode('، ', $unknown));

        // والمُعايَن منها مطابقٌ لما تعرفه الدالّة — لا مفتاحٌ ميت
        $src = (string) file_get_contents(app_path('Services/Ai/AiReviewPreview.php'));
        foreach ($previewed as $type) {
            $this->assertStringContainsString("'{$type}' =>", $src, "المعاينة تعرف {$type}");
        }
    }

    /**
     * ولا مخرج ⇒ `null` — لا نصٌّ يُختلق ليُملأ الفراغ.
     *
     * **و`najiz.statement` لا `triage`:** كانت هذه الحالة تُختبَر بـ`triage` لأنها
     * وقتَها بلا فرع معاينة. ثمّ صارت تُعايَن (حكمُ الفرز يُكتب في التذكرة ويوجّهها،
     * فاعتمادُه على بياناتٍ وصفيّة اعتمادٌ على مخرجٍ لم يُقرأ). وبقي الاختبار يصف
     * حالاً زالت — فسقط بـ«no such table» لأن الصنف بلا `RefreshDatabase` عمداً.
     *
     * و`najiz.statement` هي **الوحيدة الباقية بلا مخرجٍ محفوظ**: تعود JSON للمتصفّح
     * ولا تُخزَّن. فتبقى نيّة الاختبار وتبقى بلا قاعدة بيانات.
     */
    public function test_a_task_without_stored_output_previews_as_null(): void
    {
        $run = new AiRun(['task_type' => 'najiz.statement', 'entity_ref' => 'SB-NONE']);

        $this->assertNull(AiReviewPreview::for($run));
    }

    /** @return array<int,string> */
    private function phpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
