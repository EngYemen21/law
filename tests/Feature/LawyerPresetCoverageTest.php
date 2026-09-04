<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **القالب الجاهز يغطّي كلّ صلاحيّةٍ يطلبها مسارُ دوره.**
 *
 * كانت `اعتماد/تعديل ملخص الاستشارة` في `ROLE_PERMISSIONS['lawyer']` وغائبةً عن
 * `PRESETS['محامٍ']`، ومسارا تحرير الملخّص واعتماده يشترطانها. فمحامٍ يُنشأ بالقالب
 * يرى محرّر التقرير وزرّ الاعتماد ثمّ يُصدّ عند الضغط — عطلٌ لا يظهر إلّا يوم يُضاف
 * محامٍ جديد، بعد أن يكون الجميع قد نسي القالب.
 *
 * **والحارس مشتقٌّ من جدول المسارات لا مكتوبٌ بيد:** مسارٌ جديد بصلاحيّةٍ منسيّة
 * يُسقطه هنا، لا في الإنتاج.
 *
 * **ولا يُعمَّم على بقيّة القوالب عمداً.** «خدمة عملاء» و«إداري» **ناقصان بقصد**:
 * الأوّل لا يدير القضايا، والثاني دورٌ إداريّ لا تشغيليّ. وقرار المالك أن صلاحيّة
 * تحرير ملخّص الاستشارة **تمنحها الإدارة العليا فرداً فرداً** لا أن تُخبز في قالب.
 * فاشتراطُ التغطية عليهما كان سينقض معنى القالب الجزئيّ. والمحامي وحده هو القالب
 * الذي يجب أن يعمل صاحبُه **من فوره** بلا منحٍ إضافيّ.
 */
class LawyerPresetCoverageTest extends TestCase
{
    /**
     * كلّ صلاحيّة يطلبها وسيط `permission:` على مسارٍ تحت بادئة الدور.
     *
     * @return array<int,string>
     */
    private function permissionsDemandedUnder(string $prefix): array
    {
        $demanded = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), $prefix.'/')) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                    continue;
                }

                // `permission:أ,ب` تعني «أيٌّ منهما يكفي» — فلا تُشترط كلّها
                $names = explode(',', substr($middleware, strlen('permission:')));
                if (count($names) === 1) {
                    $demanded[] = trim($names[0]);
                }
            }
        }

        return array_values(array_unique($demanded));
    }

    public function test_the_lawyer_preset_covers_every_permission_a_lawyer_route_demands(): void
    {
        $preset = Permissions::PRESETS['محامٍ'];
        $missing = array_values(array_diff($this->permissionsDemandedUnder('lawyer'), $preset));

        $this->assertSame(
            [],
            $missing,
            "مسارُ محامٍ يشترط صلاحيّةً ليست في قالبه:\n".implode("\n", $missing)
        );
    }

    /** ولا يمنح القالب ما لا يملكه الدور أصلاً — منحٌ لا يُنفَّذ وعدٌ كاذب. */
    public function test_the_preset_stays_within_what_the_role_may_hold(): void
    {
        $extra = array_diff(Permissions::PRESETS['محامٍ'], Permissions::ROLE_PERMISSIONS['lawyer']);

        $this->assertSame([], array_values($extra));
    }

    /** والمسارُ الذي كشف العطل بعينه: تحرير ملخّص الاستشارة واعتماده. */
    public function test_the_summary_routes_are_covered_by_the_preset(): void
    {
        $this->assertContains('اعتماد/تعديل ملخص الاستشارة', Permissions::PRESETS['محامٍ']);
    }
}
