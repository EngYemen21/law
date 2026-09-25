<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **بقايا لوحة الإدارة — أربعةُ عيوبٍ صغيرةٍ كلٌّ منها يُفقد المستخدم عمله أو ثقته.**
 *
 * لا واحدَ منها عطلٌ صارخ، ولذلك بقيت: أعطالٌ لا تُنتج رسالةَ خطأ ولا شكوى، تُقرأ
 * ارتباكاً في المستخدم لا خللاً في النظام.
 */
class AdminConsultLeftoversTest extends TestCase
{
    private function screen(string $rel = 'js/pages/admin/consults.tsx'): string
    {
        return (string) preg_replace(
            '#/\*.*?\*/|//[^\n]*#su',
            '',
            (string) file_get_contents(resource_path($rel))
        );
    }

    /**
     * **Escape يغلق طبقةً واحدة.**
     *
     * كان الدرج و`Modal` يسجّلان مستمعاً للمفتاح نفسه على `document`. فضغطةٌ واحدة
     * فوق نافذةٍ مفتوحة تغلقها **وتغلق الدرجَ تحتها معاً** — يعود المستخدم إلى الجدول
     * ويُعيد فتح البطاقة من أوّلها.
     *
     * عولج هنا أوّلاً بفحص `.modal-bg.show` محلّيّاً، وبقيت الأدراج الأربعة الأخرى على
     * العطل. فصار العلاج مكدّساً مشتركاً (`useEscapeLayer` في `Modal.tsx`) يحرسه
     * `EscapeClosesOneLayerTest` للواجهة كلّها — وهذا الفحص يثبّت أنّ هذا الدرج طبقةٌ فيه.
     */
    public function test_escape_closes_one_layer_only(): void
    {
        $this->assertMatchesRegularExpression(
            '/useEscapeLayer\(\s*Boolean\(drawerRef\)/',
            $this->screen(),
            'الدرج ليس طبقةً في مكدّس الهروب المشترك — فنافذةٌ فوقه تُغلقه معها.'
        );
    }

    /**
     * **ولا خياراتٌ يستحيل اختيارها.**
     *
     * كان الاحتياطيّ يبني قائمة المحامين من الأسماء المكتوبة في البطاقات ويمنح كلاًّ
     * `id: 0`. والنموذج يرسل `lawyer_id` ويُعطَّل زرُّه بـ`!selectedLawyerId` — و`0`
     * قيمةٌ كاذبة. فالقائمة تمتلئ بأسماءٍ **يستحيل إسناد أيٍّ منها**، والمستخدم ينقر
     * الاسم فيجد الزرَّ معطَّلاً بلا سببٍ ظاهر.
     */
    public function test_no_lawyer_option_carries_a_zero_id(): void
    {
        $code = $this->screen();

        $this->assertStringNotContainsString('id: 0', $code, 'خيارٌ لا يُسنِد');
        $this->assertStringContainsString('لا محامون متاحون', $code, 'والفراغ يُعلَن بدل قائمةٍ صامتة');
    }

    /**
     * **ولا نافذةَ مركَّبةٌ لا تُفتح.**
     *
     * `summaryConsult` لم يكن يُسنَد إلّا `null` — لا مستدعيَ واحداً. فالنافذة في شجرة
     * التصيير ولا تظهر أبداً، والملخّص يُعرض أصلاً داخل الدرج بشارة حالته.
     */
    public function test_no_orphan_modal_remains(): void
    {
        $code = $this->screen();

        $this->assertStringNotContainsString('setSummaryConsult', $code);
        $this->assertStringNotContainsString('SummaryModal', $code, 'ولا استيرادٌ ميت');
        $this->assertStringContainsString('SummaryStateBadge', $code, 'وحالةُ الملخّص ما زالت تُعرض');
    }

    /**
     * **وقاعدةُ نمطٍ عامّةٍ تُعرَّف في ملفّها.**
     *
     * كانت `.modal-bg { z-index: 100000 !important }` مكتوبةً حرفيّاً في `<style>` عامّ
     * داخل **ثلاث شاشات**. فالقاعدة تسري على التطبيق كلّه متى مُوِّنت إحداها وتختفي
     * متى غادرها المستخدم — سلوكُ نافذةٍ يعتمد على الصفحة التي جاء منها.
     */
    public function test_the_global_modal_rule_lives_in_the_stylesheet(): void
    {
        foreach ([
            'js/pages/admin/consults.tsx',
            'js/pages/admin/consult-requests.tsx',
            'js/pages/employee/consults.tsx',
        ] as $rel) {
            $this->assertStringNotContainsString(
                'z-index: 100000 !important',
                $this->screen($rel),
                "{$rel}: قاعدةٌ عامّةٌ تُحقن من شاشة"
            );
        }

        $css = (string) file_get_contents(resource_path('css/babylon.css'));
        $this->assertStringContainsString('z-index:100000', $css, 'وتُعرَّف مرّةً في مصدرها');
    }
}
