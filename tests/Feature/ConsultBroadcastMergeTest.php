<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **بثّ الاستشارة لا يُنسخ خاماً فوق بطاقة الطاقم.** القناة يستمع لها العميل، فـ`status` فيها تسميته
 * (مرحلة «بانتظار اعتماد الموعد» تصل «بانتظار تحديد الموعد») و`summary` المعتمَد وحده. كانت ثلاث
 * شاشاتٍ من أربع تنسخهما فتُخفي مرحلة الاعتماد عن الإدارة حتى إعادة التحميل. القاعدة في
 * `lib/consult-live.ts` (`staffPatch` · `stageChanged`) — ويحرسها هذا الفحص.
 */
class ConsultBroadcastMergeTest extends TestCase
{
    private const STAFF_LISTENERS = [
        'resources/js/lib/consult-ui.tsx',
        'resources/js/pages/admin/consults.tsx',
        'resources/js/pages/employee/consults.tsx',
        'resources/js/pages/lawyer/consults.tsx',
    ];

    public function test_every_staff_listener_merges_through_the_shared_rule(): void
    {
        foreach (self::STAFF_LISTENERS as $path) {
            $src = (string) file_get_contents(base_path($path));

            $this->assertStringContainsString(".listen('.status'", $src, "{$path}: لم يعد يستمع — حدِّث القائمة");
            $this->assertStringContainsString('staffPatch(e)', $src, "{$path}: يدمج حمولة البثّ خاماً");
            $this->assertStringNotContainsString('{ ...x, ...e }', $src, "{$path}: يدمج حمولة البثّ خاماً");
        }

        $rule = (string) file_get_contents(resource_path('js/lib/consult-live.ts'));
        $this->assertStringContainsString('delete rest.status;', $rule);
        $this->assertStringContainsString('delete rest.summary;', $rule);
    }
}
