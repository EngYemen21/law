<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * خانةٌ واحدة نشطة في القائمة الجانبية (ملاحظة المالك 2026-09-29).
 *
 * كانت الخانة تُعلَّم متى بدأ مسار الصفحة بمسارها، فصفحة «فتح تذكرة» (`/tickets/new`) تُعلِّم
 * معها «متابعة التذاكر» (`/tickets`). الحكم الآن: أدقّ مسارٍ ينطبق بجزءٍ كامل من المسار.
 */
class SidebarActiveItemTest extends TestCase
{
    public function test_only_the_most_specific_route_is_active(): void
    {
        $src = (string) file_get_contents(resource_path('js/components/navigation/Sidebar.tsx'));

        $this->assertStringContainsString('path.startsWith(`${route}/`)', $src, 'المطابقة بجزءٍ كامل لا بحروف');
        $this->assertStringContainsString('.sort((a, b) => b.length - a.length)[0]', $src, 'أدقّ مسارٍ منطبق وحده');
        $this->assertStringNotContainsString('path.startsWith(route)', $src, 'بادئةٌ حرفيّة تُعلِّم خانتين');

        // والحالة التي كشفتها قائمة العميل: مسارٌ هو بادئة مسارٍ آخر
        $data = (string) file_get_contents(resource_path('js/lib/data.ts'));
        $this->assertStringContainsString("newticket: '/tickets/new'", $data);
        $this->assertStringContainsString("tickets: '/tickets'", $data);
    }
}
