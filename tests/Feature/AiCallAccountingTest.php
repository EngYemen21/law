<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **كلّ نداءٍ للنموذج له قيدٌ يُحصيه.**
 *
 * خمسة مواضع كانت تُنادي النموذج بلا `AiRunLogger`: فحص مستند التذكرة في فرعه
 * **الشائع**، وفحص مستند القضيّة، وفحص مستند التنفيذ، وحلقةٌ تفحص **كلّ مرفق** في
 * طلب التنفيذ، واستخراج القرارات — وهو الذي **يُنشئ التزامات على بشر**. فكانت
 * الكلفة والتغطية وصندوق المراجعة تصف نصف المنظومة وتبدو كاملة.
 *
 * والحارس بنيويّ: يمسح المستدعين فيُسقط أيّ نداءٍ للنموذج في ملفٍّ لا يُسجّل قيداً —
 * فلا يعود الحساب معتمداً على تذكّر من يكتب الموضع السادس.
 */
class AiCallAccountingTest extends TestCase
{
    /** الملفّات التي تُنادي النموذج مباشرةً وتُتوقَّع أن تُسجّل. */
    private const CALLERS = [
        'Support/TicketTriage.php',
        'Support/DecisionTasks.php',
        'Jobs/AnalyzeCaseDocumentJob.php',
        'Jobs/AnalyzeExecutionDocumentJob.php',
        'Jobs/AnalyzeExecutionJob.php',
        'Jobs/FinalizeConsultJob.php',
    ];

    public function test_every_file_that_calls_the_model_also_records_a_run(): void
    {
        $offenders = [];

        foreach (self::CALLERS as $rel) {
            $path = app_path($rel);
            if (! is_file($path)) {
                $offenders[] = "{$rel}: الملفّ مفقود — حُدِّث المسار أو احذفه من القائمة";

                continue;
            }

            // تُسقط التعليقات: بعضها يقتبس الشيفرة القديمة فيُشعل الحارس على شرحه
            $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#su', '', (string) file_get_contents($path));

            if (! str_contains($code, 'AiRunLogger::log(')) {
                $offenders[] = "{$rel}: يُنادي النموذج بلا قيد";
            }
        }

        $this->assertSame([], $offenders, "نداءٌ بلا قيد:\n".implode("\n", $offenders));
    }

    /**
     * **والغلاف الرفيع لا يُستعمل حيث توجد نسخةٌ كاملة.**
     *
     * `extractDecisions` يعيد القرارات وحدها بلا مصدرٍ ولا ثقةٍ ولا زمن، فاستعمالُه
     * يجعل القيد مستحيلاً. و`extractDecisionsResult` موجود لهذا.
     */
    public function test_the_thin_wrapper_is_not_used_where_a_traced_variant_exists(): void
    {
        $code = (string) preg_replace(
            '#/\*.*?\*/|//[^\n]*#su',
            '',
            (string) file_get_contents(app_path('Support/DecisionTasks.php'))
        );

        $this->assertStringNotContainsString('->extractDecisions(', $code, 'الغلاف الرفيع يُهدر التتبّع');
        $this->assertStringContainsString('->extractDecisionsResult(', $code);
    }

    /** ولا يُسجَّل قيدٌ بلا نداء — الثابت المقابل، وإلّا فسد الإحصاء من الجهة الأخرى. */
    public function test_a_run_is_never_recorded_without_a_call(): void
    {
        $code = (string) file_get_contents(app_path('Support/DecisionTasks.php'));

        $this->assertStringContainsString("\$result['called'] !== false", $code);
    }
}
