<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **X6 — لكلّ فعلٍ على ملفّ التنفيذ رسالة نجاحٍ بما وقع** — كانت «تم تنفيذ الإجراء» واحدةً لإرسال الأتعاب
 * واعتمادها وإعادة التسعير واستفسار العميل ورفضه للعرض (ثبت في المتصفّح 2026-09-30).
 *
 * العقد: كلّ فعلٍ يوزّعه `ExecFlowController::act` له رسالةٌ في `ACT_SUCCESS` بصفحة التنفيذ، إلّا ما تُرسله
 * بطاقة ناجز برسائلها الخاصّة (`exec-najiz.tsx`). فعلٌ جديد في الخادم بلا رسالة يُسقط هذا الاختبار.
 */
class ExecActionMessagesTest extends TestCase
{
    public function test_every_dispatched_action_has_its_own_success_message(): void
    {
        preg_match_all("/'(\\w+)' => ExecService::/", (string) file_get_contents(app_path('Http/Controllers/ExecFlowController.php')), $server);
        preg_match_all("/act\\('(\\w+)'/", (string) file_get_contents(resource_path('js/lib/exec-najiz.tsx')), $najizCard);
        $page = (string) file_get_contents(resource_path('js/pages/execflow.tsx'));
        $this->assertSame(1, preg_match('/const ACT_SUCCESS[^=]*= \{(.*?)\n\};/s', $page, $map), 'خريطة الرسائل في الصفحة');

        $needed = array_diff(array_unique($server[1]), array_unique($najizCard[1]));
        $this->assertNotEmpty($needed);
        foreach ($needed as $action) {
            $this->assertMatchesRegularExpression("/\\b{$action}: '[^']+'/u", $map[1], "الفعل «{$action}» بلا رسالة نجاح");
        }
    }
}
