<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Tests\TestCase;

/**
 * **جرد الأزرار — المرحلة ٢: الأزرار لمن تُفتح له صفحتها** (طلب المالك 2026-10-03).
 *
 * ثبت في المتصفّح على القديم (law_proof، موظّفٌ بصلاحيّات التذاكر والقضايا وحدها ومحامٍ بلا «المساعد القانوني»):
 * «حجز موعد» و«استقبال الاستشارات» و«دعوات الاجتماعات» في لوحة الموظّف، و«جدول المواعيد» في التحويلات،
 * و«مواعيد وجلسات اليوم» في القضايا، وبطاقة المساعد في لوحة المحامي، و«تنسيق اللائحة في المحرر» —
 * كلّها تُرسم ثمّ تُعيد صاحبها إلى لوحته برسالة «لا تملك صلاحية الوصول». الآن يسألها `useCanVisit` عن رابطها
 * في خريطة الخادم نفسها (`Permissions::viewMap`) — لا اسم صلاحيّةٍ منسوخ في الواجهة.
 */
class ButtonsAuditPhaseTwoTest extends TestCase
{
    private function src(string $rel): string
    {
        return (string) file_get_contents(resource_path($rel));
    }

    public function test_the_hook_reads_the_server_route_map(): void
    {
        $lib = $this->src('js/lib/permissions.ts');

        $this->assertStringContainsString('export function useCanVisit()', $lib);
        $this->assertStringContainsString('props.permCatalog?.viewMap', $lib);
        $this->assertStringContainsString('href.split(/[?#]/)[0]', $lib, 'الاستعلام لا يغيّر الصفحة المحروسة');
    }

    /** كلّ وجهةٍ يحرسها زرٌّ هنا محروسةٌ فعلاً بصلاحيّة في الخادم — وإلّا فالحارس بلا معنى. */
    public function test_every_gated_destination_is_permission_guarded_on_the_server(): void
    {
        $map = Permissions::viewMap();

        foreach (['/employee/schedule', '/employee/transfer', '/employee/consultrecv', '/employee/meetreqs', '/employee/tickets', '/employee/cases', '/employee/execs', '/lawyer/assistant', '/lawyer/tasks', '/lawyer/editor/create'] as $route) {
            $this->assertArrayHasKey($route, $map, $route);
        }
    }

    public function test_the_employee_dashboard_gates_every_navigation(): void
    {
        $dash = $this->src('js/pages/employee/dashboard.tsx');

        foreach (['schedule', 'transfer', 'consultrecv', 'meetreqs'] as $key) {
            $this->assertStringContainsString("{canVisit(R.{$key}) && (", $dash, $key);
        }
        foreach (['tickets', 'cases', 'execs'] as $key) {
            $this->assertStringContainsString("onClick={canVisit(R.{$key}) ?", $dash, "صفّ {$key}");
        }
        $this->assertSame(0, substr_count($dash, 'className="click" onClick='), 'لا صفّ قابلٌ للنقر بلا سؤال');
        $this->assertSame(3, substr_count($dash, '{canVisit(R.schedule) && ('), '«حجز موعد» في الرأس، و«الجدولة» و«حجز موعد جديد» في جدول اليوم');
    }

    public function test_page_headers_and_lawyer_panels_are_gated(): void
    {
        $transfer = $this->src('js/pages/employee/transfer.tsx');
        $this->assertStringContainsString("{canVisit('/employee/tickets') && (", $transfer);
        $this->assertStringContainsString("{canVisit('/employee/schedule') && (", $transfer);

        $cases = $this->src('js/pages/employee/cases.tsx');
        $this->assertStringContainsString("{canVisit('/employee/schedule') && (", $cases);
        $this->assertStringContainsString("{canVisit('/employee/tickets') && (", $cases);

        $lawyer = $this->src('js/pages/lawyer/dashboard.tsx');
        $this->assertStringContainsString("{canVisit('/lawyer/assistant') && (", $lawyer);
        $this->assertStringContainsString("{canVisit('/lawyer/tasks') && (", $lawyer);

        $this->assertStringContainsString("{canVisit('/lawyer/editor/create') && (", $this->src('js/pages/lawyer/case.tsx'));
    }
}
