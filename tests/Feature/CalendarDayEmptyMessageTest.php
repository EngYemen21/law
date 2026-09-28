<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **يومٌ فارغ ليس تقويماً فارغاً** — أجندة اليوم في `UnifiedCalendar` (المشترك بين الأدوار الأربعة) كانت
 * تقول «لا توجد مواعيد في تقويمك» وعدّاد التقويم فوقها يذكر مواعيد.
 */
class CalendarDayEmptyMessageTest extends TestCase
{
    public function test_an_empty_day_in_a_non_empty_calendar_says_so(): void
    {
        $src = (string) file_get_contents(resource_path('js/components/babylon/UnifiedCalendar.tsx'));

        $this->assertStringContainsString("<b>{items.length ? 'لا مواعيد في هذا اليوم' : emptyMessage}</b>", $src);
    }
}
