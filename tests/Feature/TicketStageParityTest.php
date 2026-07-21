<?php

namespace Tests\Feature;

use App\Support\TicketJourney;
use Tests\TestCase;

/**
 * تطابق خريطة المراحل بين الواجهة والخادم.
 * `tktStage()` في resources/js/lib/chat.ts و`TicketJourney::indexOf()` خريطتان مُزامَنتان
 * يدوياً؛ انحراف إحداهما يجعل شريط الرحلة يعرض مرحلة غير التي ينفّذها الخادم — بصمت.
 * هذا الاختبار يكسر البناء عند أول انحراف.
 */
class TicketStageParityTest extends TestCase
{
    /** @return array<string,int> خريطة الحالة→الفهرس كما تراها الواجهة */
    private function frontendMap(): array
    {
        $path = base_path('resources/js/lib/chat.ts');
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);

        // جسم دالة tktStage فقط — كي لا نلتقط أرقاماً من دوال أخرى
        $this->assertSame(1, preg_match('/export function tktStage\([^)]*\)[^{]*\{(.*?)\n\}/s', $src, $fn));

        preg_match_all("/'([^']+)'\s*:\s*(\d+)/u", $fn[1], $m, PREG_SET_ORDER);
        $map = [];
        foreach ($m as $pair) {
            $map[$pair[1]] = (int) $pair[2];
        }

        return $map;
    }

    public function test_frontend_stage_map_matches_backend_index(): void
    {
        $frontend = $this->frontendMap();
        $this->assertNotEmpty($frontend, 'تعذّر استخراج خريطة tktStage من chat.ts');

        // كل حالة معتمدة في الخادم موجودة في الواجهة بنفس الفهرس
        foreach (TicketJourney::statuses() as $status) {
            $this->assertArrayHasKey($status, $frontend, "الحالة «{$status}» غير موجودة في tktStage بالواجهة");
            $this->assertSame(
                TicketJourney::indexOf($status),
                $frontend[$status],
                "فهرس المرحلة للحالة «{$status}» مختلف بين الواجهة والخادم",
            );
        }
    }

    public function test_frontend_has_no_status_the_server_rejects(): void
    {
        $accepted = TicketJourney::statuses();

        foreach (array_keys($this->frontendMap()) as $status) {
            $this->assertContains($status, $accepted, "الواجهة تعرف حالة «{$status}» يرفضها الخادم");
        }
    }

    public function test_stage_count_matches_flow_line_labels(): void
    {
        $src = (string) file_get_contents(base_path('resources/js/lib/chat.ts'));
        $this->assertSame(1, preg_match('/export const TKT_LIFE = \[(.*?)\]/s', $src, $m));
        $labels = preg_match_all("/'[^']+'/u", $m[1]);

        $this->assertSame(count(TicketJourney::STAGES), $labels, 'عدد مراحل شريط الرحلة يخالف STAGES');
    }
}
