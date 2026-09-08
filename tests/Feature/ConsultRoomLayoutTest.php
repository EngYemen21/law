<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **غرفةُ الاستشارة تلبس ثوبَ غرفة الاجتماع — بلا كسرِ وظيفة.**
 *
 * المكوّن `ZoomEmbedRoom` فيه مساران: بلا `details` عمودٌ بسيط، ومعها الغرفةُ الكاملة
 * (مسرحُ فيديو + جانبيّة + مؤقّت + شارةُ تسجيل + مغادرة + علامةٌ مائيّة). وكانت
 * الاستشارة لا تمرّرها فتحرم نفسها من التصميم بلا سبب.
 *
 * وحراسةُ عدم الكسر أهمّ من حراسة الشكل: بطاقةُ الملاحظات وزرُّ الإنهاء المشروط
 * يعيشان **خارج** المكوّن، ويجب أن يبقيا كما هما.
 */
class ConsultRoomLayoutTest extends TestCase
{
    private function ui(): string
    {
        return file_get_contents(resource_path('js/lib/consult-ui.tsx'));
    }

    // ————— ١ · المفتاح الذي يحوّل المسار —————

    public function test_the_consult_room_passes_details_to_the_shared_component(): void
    {
        $ui = $this->ui();

        $this->assertStringContainsString(
            'kind="consult"',
            $ui,
            'نوعُ الجلسة يُصرَّح به لا يُترك للقيمة الافتراضيّة'
        );
        // **السِّمة كاملةً لا جزءاً منها**: فحصُ `details={{` وحده ينجو من `xdetails={{`
        // — جرّبتُه فنجا. السطرُ المجرَّد هو ما يُثبت السِّمة.
        $lines = array_map('trim', explode(chr(10), str_replace(chr(13), '', $ui)));
        $this->assertContains(
            'details={{',
            $lines,
            'بلا `details` يعود ZoomEmbedRoom إلى الغرفة البسيطة — وهو العطل نفسه'
        );
        $this->assertStringContainsString('status: consult.session,', $ui);
        $this->assertStringContainsString('title: consult.subject', $ui);
    }

    // ————— ٢ · لا صفَّ يشير إلى حقلٍ لا وجود له —————

    public function test_every_detail_row_maps_to_a_real_consult_card_field(): void
    {
        $ui = $this->ui();

        // الحقول المُعلَنة في واجهة البطاقة
        preg_match('/export interface ConsultCard \{(.*?)\n\}/su', $ui, $iface);
        $this->assertNotEmpty($iface, 'تعذّر قراءة واجهة ConsultCard');

        // الحقول المستعملة داخل كتلة rows
        $start = strpos($ui, 'rows: [');
        $this->assertNotFalse($start, 'كتلة rows غائبة');
        $rows = substr($ui, $start, strpos($ui, "\n          ],", $start) - $start);

        preg_match_all('/consult\.(\w+)/u', $rows, $used);
        $this->assertNotEmpty($used[1], 'لا حقول في rows');

        foreach (array_unique($used[1]) as $field) {
            $this->assertMatchesRegularExpression(
                '/^\s+'.preg_quote($field, '/').'[?]?:/mu',
                $iface[1],
                "الصفّ يعرض `consult.{$field}` وهو غير مُعلَنٍ في ConsultCard — يظهر فارغاً أبداً"
            );
        }
    }

    // ————— ٣ · وجهةُ شاشة النهاية موجودةٌ للأدوار الثلاثة —————

    public function test_the_summary_link_points_at_a_registered_route(): void
    {
        $this->assertStringContainsString('${base}/consult?ref=', $this->ui());

        foreach (['employee.consult', 'lawyer.consult', 'admin.consult'] as $name) {
            $this->assertTrue(Route::has($name), "المسار {$name} غير مسجَّل — زرُّ «صفحة الاستشارة» يقع في فراغ");
        }
    }

    // ————— ٤ · المفردات تتبع نوع الجلسة —————

    public function test_the_room_does_not_call_a_consult_a_meeting(): void
    {
        $room = file_get_contents(resource_path('js/lib/zoom-room.tsx'));

        // الاسم يُشتقّ مرّةً من `kind` بدل نصوصٍ مكتوبةٍ للاجتماعات
        $this->assertStringContainsString("const noun = kind === 'meeting' ? 'الاجتماع' : 'الاستشارة';", $room);
        $this->assertStringNotContainsString("'صفحة الاجتماع' : 'رجوع'", $room);
        $this->assertStringNotContainsString('>رجوع للاجتماعات</button>', $room);
        // و«المحضر» ليس من مخرجات الاستشارة
        $this->assertStringNotContainsString('الاطّلاع على المحضر والملخّص من صفحة الاجتماع', $room);
    }

    // ————— ٥ · حارسُ عدم الكسر —————

    public function test_the_notes_card_and_its_guarded_button_survive_the_redesign(): void
    {
        $ui = $this->ui();

        // الزرّ معطَّلٌ حتّى تنعقد الجلسة — والخادم يرفضه قبلها
        $this->assertStringContainsString('disabled={!running}', $ui);
        $this->assertStringContainsString('إنهاء وكتابة الملخص', $ui);
        // ومسارُ الإنهاء ووجهتُه كما هما
        $this->assertStringContainsString('/consults/${consult.id}/end', $ui);
        $this->assertStringContainsString('${base}/consultrecv', $ui);
    }
}
