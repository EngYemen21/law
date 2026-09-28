<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * **حرّاس مراجعة الجدولة (2026-09-28).**
 *
 * ١) «اليوم» بالتوقيت المحلّي لا UTC: `toISOString()` يعطي أمسَ من منتصف الليل حتى ٠٣:٠٠ بتوقيت الرياض —
 *    ثبت في المتصفّح (زرّ «اليوم» في إدارة الاجتماعات، وأدنى تاريخ في حجز محادثة التذكرة)، وفلتر «هذا الشهر»
 *    كان يبدأ من آخر أيّام الشهر السابق طوال اليوم. المصدر الواحد `resources/js/lib/local-date.ts`.
 * ٢) حالات الجدولة بالتعدادات لا بالنصوص العربيّة (قاعدة CLAUDE.md) — القيم نفسها، فالسلوك لم يتغيّر.
 */
class LocalDateAndStatusEnumsTest extends TestCase
{
    public function test_no_page_computes_a_date_from_utc(): void
    {
        $offenders = collect(File::allFiles(resource_path('js')))
            ->reject(fn ($f) => str_ends_with($f->getPathname(), 'lib/local-date.ts'))
            ->filter(fn ($f) => preg_match("/toISOString\(\)\.(split\('T'\)\[0\]|slice\(0, ?10\))/", $f->getContents()) === 1)
            ->map(fn ($f) => str_replace(resource_path('js').'/', '', $f->getPathname()))
            ->values()->all();

        $this->assertSame([], $offenders, 'تاريخٌ محسوبٌ بـUTC: استعمل todayISO/dateISOAfter/firstOfMonthISO');
        $this->assertStringContainsString('export function firstOfMonthISO()', (string) file_get_contents(resource_path('js/lib/local-date.ts')));
    }

    public function test_scheduling_code_compares_statuses_through_enums(): void
    {
        $literals = ["'بانتظار التسعير'", "'بانتظار السداد'", "'بانتظار تحديد الموعد'", "'بانتظار اعتماد الموعد'", "'بانتظار الجلسة'", "'جلسة جارية'", "'مجدولة'", "'قادم'", "'بانتظار الاعتماد'"];
        $files = [
            'Support/ConsultBooking.php', 'Http/Controllers/Staff/ConsultController.php', 'Http/Controllers/Staff/MeetingController.php',
            'Console/Commands/AutoLapseHearings.php', 'Console/Commands/SendHearingReminders.php', 'Console/Commands/AutoLapseAppointments.php',
            'Console/Commands/SendConsultReminders.php', 'Console/Commands/SendMeetingReminders.php',
        ];

        foreach ($files as $file) {
            $src = (string) file_get_contents(app_path($file));
            foreach ($literals as $literal) {
                $this->assertDoesNotMatchRegularExpression('/(===|!==|where\([^)]*)\s*'.preg_quote($literal, '/').'/u', $src, "{$file}: {$literal}");
            }
        }

        $schedule = (string) file_get_contents(resource_path('js/pages/employee/schedule.tsx'));
        $this->assertStringNotContainsString("a.status !== 'تم الحضور'", $schedule);
        $this->assertStringNotContainsString("a.status !== 'لم يحضر'", $schedule);
    }

    /** الشاشة الواحدة باسمٍ واحد في لوحتي الإدارة والموظّف (قرار المالك). */
    public function test_the_booking_screen_has_one_name(): void
    {
        $this->assertStringContainsString('listLabel="جدولة وتفرغ المستشارين"', (string) file_get_contents(resource_path('js/pages/employee/calendar.tsx')));
        $this->assertStringContainsString('جدولة وتفرغ المستشارين', (string) file_get_contents(resource_path('js/pages/admin/calendar.tsx')));
    }
}
