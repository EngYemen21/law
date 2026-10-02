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

    // ————— ٦ · الجلسة في صفحتها المستقلّة بتبويبها (قرار المالك 2026-10-03) —————

    /**
     * Component View يُلحق نوافذه وقوائمه بـ`body` بـ`z-index` تلقائيّ أو `2` (توثيق Zoom import-sdk، وثبت في
     * حزمة 6.2.0)، وفريق Zoom يمنع تغيير طبقاته. فكانت النافذة المصغّرة وطبقات الغرفة (80–87) فوقها: موافقة
     * التسجيل وقائمة «End Meeting for All» لا تُنقر. الآن: صفحةٌ مستقلّة — «Dedicated route… recommended».
     */
    public function test_the_room_is_a_dedicated_page_without_a_floating_player(): void
    {
        $session = $this->src('js/lib/room-session.ts');
        $room = $this->src('js/lib/zoom-room.tsx');
        $app = $this->src('js/app.tsx');

        $this->assertStringNotContainsString('RoomDock', $app, 'لا نافذة مصغّرة فوق الصفحات');
        $this->assertStringNotContainsString('RoomDock', $room);
        $this->assertStringNotContainsString('createPortal', $room, 'الغرفة في تدفّق صفحتها لا طبقةٌ على body');
        $this->assertStringNotContainsString('document.body.appendChild', $session, 'حاوية Zoom في مساحة الصفحة');
        $this->assertStringContainsString('ref={mountZoom}', $room);
        $this->assertStringContainsString('RoomRoute.layout = (page) => page;', $room, 'بلا تخطيط اللوحة');
        $this->assertStringContainsString('leaveOnPageUnload: true', $session, 'مغادرة الصفحة مغادرةٌ للاجتماع');
        $this->assertStringContainsString('return () => unmountRoom();', $room);
        $this->assertStringContainsString("addEventListener('beforeunload'", $session);
    }

    /**
     * كلّ دخولٍ إلى غرفة يفتح تبويب الجلسة — يُفحص **كلّ** ملفّ واجهة لا قائمةٌ ثابتة (فاتت جولةَ البحث الأولى
     * أزرارُ `slink` في صفحة المحامي، وكشفها المتصفّح). وروابط الخادم العامّة (الإشعارات والتنبيهات) تمرّ بـ`visitHref`.
     */
    public function test_every_entry_opens_the_room_tab(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));
        $checked = 0;

        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (! preg_match('/\.tsx?$/', $path) || str_contains($path, '/js/actions/') || str_contains($path, '/js/routes/') || str_contains($path, '/js/wayfinder/')) {
                continue;
            }
            $src = (string) file_get_contents($path);
            $checked++;
            $this->assertDoesNotMatchRegularExpression('/router\.visit\([^)]*(videoroom|meetingroom|consults\/room|joinLink|slink|meetLink)/', $src, "دخولٌ بالانتقال داخل التبويب في {$path}");
            $this->assertDoesNotMatchRegularExpression('/href=\{[^}]*(joinLink|slink|meetLink|videoroom|meetingroom|consults\/room)/', $src, "رابط دخولٍ لا يمرّ بتبويب الجلسة في {$path}");
        }

        $this->assertGreaterThan(100, $checked);
        $this->assertStringContainsString('visitHref(item.link)', $this->src('js/components/navigation/NotificationDropdown.tsx'));
        $this->assertStringContainsString('visitHref(alert.link)', $this->src('js/pages/dashboard.tsx'));
        $this->assertStringContainsString('visitHref(alert.link)', $this->src('js/pages/lawyer/dashboard.tsx'));
    }

    // ————— ٧ · الأنماط —————

    public function test_room_styles_follow_the_direction_and_fill_the_tab(): void
    {
        $css = (string) file_get_contents(resource_path('css/babylon.css'));

        $this->assertMatchesRegularExpression('/\.mroom-row \.v\{[^}]*text-align:end/u', $css);
        $this->assertDoesNotMatchRegularExpression('/\.mroom[^{]*\{[^}]*text-align:left/u', $css, 'محاذاةٌ يساريّة ثابتة تكسر الاتّجاه');
        $this->assertDoesNotMatchRegularExpression('/\.mroom[^{]*\{[^}]*min-height:520px/u', $css);
        // لا طبقة فوق Zoom: نوافذه على body بـz-index تلقائيّ — أيّ z-index في الغرفة يعلوها
        $room = substr($css, (int) strpos($css, '.mroom-page{'), (int) strpos($css, '.mroom-row .v{') - (int) strpos($css, '.mroom-page{'));
        $this->assertDoesNotMatchRegularExpression('/z-index/', $room, 'طبقةٌ فوق نوافذ Zoom');
        $this->assertDoesNotMatchRegularExpression('/position:fixed/', $room);
        $this->assertStringNotContainsString('.mroom-dock', $css);
    }
}
