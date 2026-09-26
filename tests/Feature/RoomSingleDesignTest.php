<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **غرفةُ الجلسة المرئيّة واحدة — حارسُ قرارات المالك (2026-09-26).**
 *
 * كانت للغرفة صورتان (عمودٌ بسيط للعميل في الاستشارة، وغرفةٌ كاملة للباقين)، ولكلّ دورٍ بطاقةُ
 * إنهاءٍ تحت الغرفة بشرطٍ مختلف، وصفُّ «المدة» مُعلَناً سلفاً، وشارةُ «تسجيل» تُشتقّ من نوع الجلسة
 * فيراها العميل في الاجتماع ولا يراها الطاقم في الاستشارة. والتنقّل بين الصفحات يُسقط المكالمة.
 *
 * هذا الملفّ يمسح مصدر الواجهة (بعد حذف التعليقات) ويفشل إن عاد شيءٌ من ذلك:
 * - تصميمٌ واحد: الصفحات الثماني تعرض `RoomRoute` وحده، ولا مسارَ بسيطاً في المكوّن.
 * - شارة التسجيل للطاقم وحده ومن الخادم وحده — لا من أحداث Zoom.
 * - الإنهاء داخل الغرفة بشرطٍ واحد `endAction.enabled`، ولا `endMeeting` من الواجهة (المغادرة ليست إنهاءً).
 * - لا صفّ «المدة»، والمدّة المقيسة بعد الانتهاء.
 * - المكالمة تعيش خارج الصفحات (حاويةٌ على `body` وشريطٌ عائم في جذر التطبيق).
 */
class RoomSingleDesignTest extends TestCase
{
    private function src(string $rel): string
    {
        $code = (string) file_get_contents(resource_path($rel));

        // التعليقات تشرح التاريخ («كانت …») فلا تُحتسب — الحارس على الشيفرة الحيّة وحدها
        return (string) preg_replace('#/\*.*?\*/|(?<![:"\'])//[^\n]*|\{/\*.*?\*/\}#su', '', $code);
    }

    /** @return array<string, array{string}> */
    public static function roomPages(): array
    {
        $pages = [];

        foreach (['', 'admin/', 'employee/', 'lawyer/'] as $role) {
            foreach (['videoroom', 'meetingroom'] as $kind) {
                $rel = "js/pages/{$role}{$kind}.tsx";
                $pages[$rel] = [$rel];
            }
        }

        return $pages;
    }

    // ————— ١ · تصميمٌ واحد —————

    #[DataProvider('roomPages')]
    public function test_every_room_page_renders_the_one_room(string $rel): void
    {
        $page = $this->src($rel);

        $this->assertStringContainsString('RoomRoute', $page, "{$rel} لا يعرض الغرفة الواحدة");

        foreach (['ZoomEmbedRoom', 'StaffVideoRoomPage', 'StaffMeetingRoom', 'selfName', 'selfAv', 'viewer=', 'details={{'] as $old) {
            $this->assertStringNotContainsString($old, $page, "«{$old}» عاد إلى {$rel} — الغرفة تقرأ عقد الخادم `room` وحده");
        }
    }

    public function test_the_simple_layout_does_not_return(): void
    {
        $room = $this->src('js/lib/zoom-room.tsx');

        $this->assertStringNotContainsString('! details', $room, 'مسارُ «بلا details» هو الغرفة البسيطة');
        $this->assertStringNotContainsString('maxWidth: 900', $room);
        $this->assertStringNotContainsString('minHeight: 520', $room, 'ارتفاعٌ ثابت يكسر ملءَ التبويب على الهاتف');

        foreach (['js/lib/consult-ui.tsx', 'js/lib/meeting-ui.tsx'] as $rel) {
            $this->assertStringNotContainsString('ZoomEmbedRoom', $this->src($rel), "غرفةٌ ثانية في {$rel}");
        }
    }

    // ————— ٢ · شارة التسجيل: للطاقم، ومن الخادم —————

    public function test_the_recording_badge_is_staff_only_and_server_driven(): void
    {
        $room = $this->src('js/lib/zoom-room.tsx');
        $session = $this->src('js/lib/room-session.ts');

        $this->assertSame(1, substr_count($room, 'className="vr-rec"'), 'الشارة تُرسم في موضعٍ واحد (RecordingBadge)');
        $this->assertStringContainsString('isStaffRoom(room) && room.recording', $room, 'الشارة بلا شرط الطاقم يراها العميل');
        $this->assertStringNotContainsString("kind === 'meeting'; ", $room, 'التسجيل لا يُشتقّ من نوع الجلسة');

        // `recording` من البثّ يُقرأ من قناة الطاقم وحدها
        $this->assertStringContainsString('fromStaffChannel && isStaffRoom(room)', $session);
        // ولا يُستنتج من أحداث Zoom
        $this->assertDoesNotMatchRegularExpression("/on\\?*\\.?\\(\\s*'recording/u", $session, 'حدث تسجيل Zoom يكشف التسجيل للعميل');
    }

    // ————— ٣ · الإنهاء داخل الغرفة بشرطٍ واحد، والمغادرة ليست إنهاءً —————

    public function test_ending_lives_in_the_room_with_one_rule(): void
    {
        $room = $this->src('js/lib/zoom-room.tsx');
        $session = $this->src('js/lib/room-session.ts');

        $this->assertStringContainsString('disabled={!end.enabled}', $room);
        $this->assertStringContainsString('usePrompt', $room, 'التدوين في نافذة الإدخال المشتركة لا بطاقةٌ تحت الغرفة');
        $this->assertStringNotContainsString('running', $room, 'شرطٌ ثانٍ للإنهاء يُحسب في الواجهة');
        $this->assertStringNotContainsString('endMeeting(', $session, 'الواجهة لا تُنهي الجلسة للجميع — ذلك فعل الخادم عبر endAction');
        $this->assertStringNotContainsString('endMeeting(', $room);
    }

    // ————— ٤ · لا «مدة» مُعلَنة، والمقيسة بعد الانتهاء —————

    public function test_no_duration_row_and_measured_duration_after_end(): void
    {
        $room = $this->src('js/lib/zoom-room.tsx');

        $this->assertDoesNotMatchRegularExpression("/'المد[ّ]?ة'/u", $room);
        $this->assertStringContainsString('room.measuredDuration', $room);
    }

    // ————— ٥ · سببُ الرفض من الخادم —————

    public function test_the_signature_refusal_reason_is_shown(): void
    {
        $session = $this->src('js/lib/room-session.ts');

        $this->assertStringContainsString('function refusalMessage(', $session);
        $this->assertStringContainsString('fail(refusalMessage(e))', $session);
    }

    // ————— ٦ · المكالمة تعيش خارج الصفحات —————

    public function test_the_call_survives_navigation(): void
    {
        $session = $this->src('js/lib/room-session.ts');
        $app = $this->src('js/app.tsx');

        $this->assertStringContainsString('document.body.appendChild(host)', $session, 'حاوية Zoom داخل الصفحة تُفكَّك مع التنقّل');
        $this->assertStringContainsString("addEventListener('beforeunload'", $session);
        $this->assertStringContainsString('<RoomDock />', $app, 'بلا الشريط العائم في الجذر لا عودة إلى الجلسة من صفحةٍ أخرى');
    }

    // ————— ٧ · الأنماط —————

    public function test_room_styles_follow_the_direction_and_fill_the_tab(): void
    {
        $css = (string) file_get_contents(resource_path('css/babylon.css'));

        $this->assertMatchesRegularExpression('/\.mroom-row \.v\{[^}]*text-align:end/u', $css);
        $this->assertDoesNotMatchRegularExpression('/\.mroom[^{]*\{[^}]*text-align:left/u', $css, 'محاذاةٌ يساريّة ثابتة تكسر الاتّجاه');
        $this->assertDoesNotMatchRegularExpression('/\.mroom[^{]*\{[^}]*min-height:520px/u', $css);
        $this->assertStringNotContainsString('.mroom-grid', $css, 'عمودٌ جانبيّ يقلّص الفيديو — التفاصيل درجٌ فوقه');
    }
}
