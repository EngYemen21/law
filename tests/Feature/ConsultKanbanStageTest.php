<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **أعمدة كانبان الاستشارات من مرحلة الحجز (`bookingStage`) لا من نصّ الحالة.** كان عمود الجدولة
 * في «إدارة الاستشارات» يُسقط «بانتظار اعتماد الموعد» إلى «المراجعة»، وشاشة الطلبات تكرّر لكلّ عمودٍ
 * ثلاث مقارناتٍ نصّيّة وقائمةً محلّيّة (`SCHEDULE_STAGE`).
 */
class ConsultKanbanStageTest extends TestCase
{
    public function test_both_kanbans_group_by_booking_stage(): void
    {
        $requests = (string) file_get_contents(resource_path('js/pages/admin/consult-requests.tsx'));
        $this->assertStringContainsString("scheduling: filteredItems.filter((c) => c.bookingStage === 'scheduling' || c.bookingStage === 'approval')", $requests);
        $this->assertStringNotContainsString('SCHEDULE_STAGE', $requests);
        $this->assertStringNotContainsString("c.status === 'بانتظار التسعير'", $requests);

        $consults = (string) file_get_contents(resource_path('js/pages/admin/consults.tsx'));
        $this->assertStringContainsString("c.bookingStage === 'scheduling' || c.bookingStage === 'approval'", $consults);
    }
}
