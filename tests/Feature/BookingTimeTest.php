<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * **قرارات وقت الحجز في نوافذ الجدولة** (`resources/js/lib/booking-time.ts`) — تُشغَّل في node على الملفّ نفسه.
 *
 * بلاغ المالك 2026-09-30: «عندما أدخل الوقت المخصّص في لوحة المحامي يختفي عند حجز استشارة مع العميل». كانت نافذة
 * الدعوة تمسح كلّ وقتٍ ليس من الشرائح الجاهزة، ونافذة محادثة التذكرة تمسحه عند كلّ تحميلٍ للشرائح؛ وشبكة التفرّغ تكتب
 * فترة ساعة البداية بجانب وقت النهاية؛ ونافذة «حجز موعد جديد» تُفتح على أوّل عميلٍ لا على صاحب الاستشارة المدفوعة.
 */
class BookingTimeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function evalTs(string $body): array
    {
        if (! Process::run(['node', '--version'])->successful()) {
            $this->markTestSkipped('node غير متاح في هذه البيئة.');
        }

        $module = 'file:///'.ltrim(str_replace(chr(92), '/', resource_path('js/lib/booking-time.ts')), '/');
        $script = 'import { keepChosenTime, periodOf, bookingClientId } from '.json_encode($module).';'
            .' process.stdout.write(JSON.stringify('.$body.'));';

        $result = Process::run(['node', '--input-type=module', '-e', $script]);
        if (! $result->successful()) {
            $this->markTestSkipped('تعذّر تحميل ملفّ TypeScript في node: '.$result->errorOutput());
        }

        return json_decode($result->output(), true);
    }

    public function test_a_custom_time_survives_a_slot_refresh(): void
    {
        $slots = '[{time:"10:00",taken:false},{time:"11:00",taken:true},{time:"12:00",taken:false}]';

        $out = $this->evalTs('{
            custom: keepChosenTime("11:20", '.$slots.'),
            freeSlot: keepChosenTime("10:00", '.$slots.'),
            takenSlot: keepChosenTime("11:00", '.$slots.'),
            noSlots: keepChosenTime("11:20", []),
            empty: keepChosenTime("", '.$slots.')
        }');

        $this->assertSame('11:20', $out['custom'], 'الوقت المخصّص ليس شريحةً فلا يُمسح');
        $this->assertSame('10:00', $out['freeSlot']);
        $this->assertSame('', $out['takenSlot'], 'الشريحة التي صارت محجوزة وحدها تُمسح');
        $this->assertSame('11:20', $out['noSlots'], 'يومٌ بلا شرائح: الخادم يحكم برسالة، لا مسحٌ صامت');
        $this->assertSame('', $out['empty']);
    }

    public function test_the_period_follows_the_time_it_is_written_next_to(): void
    {
        $out = $this->evalTs('["09:00","11:59","12:00","12:20","13:00","21:00"].map(periodOf)');

        $this->assertSame(['صباحاً', 'صباحاً', 'ظهراً', 'ظهراً', 'مساءً', 'مساءً'], $out);
    }

    public function test_the_booking_window_opens_on_a_client_with_a_paid_consult_awaiting_a_slot(): void
    {
        $out = $this->evalTs('{
            fresh: bookingClientId("", [5, 9], [4, 5, 9]),
            keepsReady: bookingClientId(9, [5, 9], [4, 5, 9]),
            leavesDone: bookingClientId(4, [5], [4, 5]),
            noneAwaiting: bookingClientId("", [], [4, 5]),
            keepsChoice: bookingClientId(5, [], [4, 5]),
            noClients: bookingClientId("", [], [])
        }');

        $this->assertSame(5, $out['fresh'], 'كانت تُفتح على العميل 4 — أوّل القائمة — فيبدو زرّ التأكيد معطّلاً');
        $this->assertSame(9, $out['keepsReady']);
        $this->assertSame(5, $out['leavesDone'], 'عميلٌ حُجزت استشارته لا تُعاد النافذة عليه');
        $this->assertSame(4, $out['noneAwaiting']);
        $this->assertSame(5, $out['keepsChoice']);
        $this->assertSame('', $out['noClients']);
    }

    /** النوافذ الثلاث تقرأ القرار من الملفّ الواحد — لا نسخةَ تمسح المخصّص بشرط «ليس في الشرائح». */
    public function test_the_windows_use_the_shared_rules(): void
    {
        $meeting = (string) file_get_contents(resource_path('js/lib/meeting-ui.tsx'));
        $chat = (string) file_get_contents(resource_path('js/pages/employee/ticketchat.tsx'));
        $schedule = (string) file_get_contents(resource_path('js/pages/employee/schedule.tsx'));

        $this->assertStringNotContainsString('!availableSlots.includes(miTime)', $meeting);
        $this->assertStringContainsString('keepChosenTime(miTime, allSlotsWithStatus)', $meeting);
        $this->assertStringNotContainsString("setSchedTime(''); })", $chat);
        $this->assertStringContainsString('keepChosenTime(t, loaded)', $chat);
        $this->assertStringContainsString('periodOf(endH)', $schedule);
        $this->assertStringContainsString("useState<number | ''>('')", $schedule);
    }
}
