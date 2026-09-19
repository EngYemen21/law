<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **روابط بطاقات القرار في محادثة التذكرة (العميل والطاقم)**.
 *
 * كان زرّ «الانتقال لملف التنفيذ» في `pages/ticketchat.tsx` يشير إلى `/executions` وهو مسارٌ لا
 * وجود له في `routes/web.php` (المسار الحقيقيّ `/execs`) فيسقط العميل على صفحة 404، وكذلك
 * `${base}/executions` في بطاقة قرار المسار لدى الطاقم. وكان زرّ «حجز موعد الاستشارة» يمرّر إلى
 * محدّد `.book-consult` الذي لا يحمله أيّ عنصر فلا يفعل النقر شيئاً؛ صار يمرّر إلى بطاقة الحجز
 * `#book-consult` في الصفحة نفسها، وإن لم تكن معروضة ينتقل إلى صفحة الحجز `/book`.
 *
 * هذا الحارس يمنع عودة الهدفين الميّتين ويتحقّق من وجود المسارات البديلة فعلاً.
 */
class ClientTicketChatLinksTest extends TestCase
{
    private function ui(string $relative): string
    {
        $path = base_path($relative);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_the_dead_targets_are_gone_from_the_client_chat(): void
    {
        $src = $this->ui('resources/js/pages/ticketchat.tsx');

        $this->assertStringNotContainsString("'/executions'", $src, 'عاد المسار غير الموجود /executions');
        $this->assertStringNotContainsString('"/executions"', $src, 'عاد المسار غير الموجود /executions');
        $this->assertStringNotContainsString('.book-consult', $src, 'عاد المحدّد الميّت .book-consult');

        // الهدفان الصحيحان حاضران: تبويب التنفيذ، وبطاقة الحجز في الصفحة نفسها
        $this->assertStringContainsString('href="/execs"', $src);
        $this->assertStringContainsString('id="book-consult"', $src);
        $this->assertStringContainsString("getElementById('book-consult')", $src);
    }

    public function test_the_staff_decision_card_points_at_execs_too(): void
    {
        $src = $this->ui('resources/js/components/babylon/TicketTrackDecisionCard.tsx');

        $this->assertStringNotContainsString('/executions', $src, 'عاد المسار غير الموجود /executions لبطاقة الطاقم');
        $this->assertStringContainsString('${base}/execs', $src);
    }

    public function test_the_replacement_routes_really_exist(): void
    {
        foreach (['execs', 'book'] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), "المسار «{$name}» غير مسجّل");
        }
        // ولا وجود لمسار /executions أصلاً
        $this->assertNull(Route::getRoutes()->getByName('executions'));
    }
}
