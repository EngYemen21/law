<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * أزرار الفلترة على الهاتف (طلب المالك 2026-09-29) — والحارس نفسه لصندوق الرسالة الثابت.
 *
 * الترتيب على الهاتف لا تحمله كلّ صفحة بقاعدةٍ من عندها، بل صنفان مشتركان:
 * - `filter-pills`: شريط أزرار أفقيّ واحد يُسحب باليد بدل أن يلتفّ صفوفاً متفاوتة.
 * - `filter-selects`: القوائم المنسدلة اثنتين في كلّ صفّ، وخانة البحث بعرض كامل.
 */
class MobileFiltersLayoutTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/babylon.css'));
    }

    public function test_mobile_rules_turn_pills_into_a_swipe_strip_and_selects_into_two_columns(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/\.filter-pills\{flex-wrap:nowrap!important;overflow-x:auto/u', $css);
        $this->assertMatchesRegularExpression('/\.filter-selects\{display:grid!important;grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/u', $css);
    }

    public function test_filter_bars_use_the_shared_classes_instead_of_page_rules(): void
    {
        $pages = [
            'filter-pills' => ['tickets', 'cases', 'myconsults', 'meetings', 'admin/tickets', 'admin/cases', 'admin/approvals', 'admin/distribute', 'employee/tickets', 'employee/cases', 'lawyer/tickets', 'lawyer/dashboard'],
            'filter-selects' => ['admin/audit-logs', 'admin/journey-transitions', 'admin/tasks', 'lawyer/tickets', 'employee/tickets', 'employee/cases', 'employee/transfer'],
        ];

        foreach ($pages as $class => $files) {
            foreach ($files as $file) {
                $src = (string) file_get_contents(resource_path("js/pages/{$file}.tsx"));
                $this->assertStringContainsString($class, $src, "{$file}: شريط الفلترة بلا الصنف المشترك {$class}");
            }
        }
    }

    public function test_chat_composer_stays_on_screen_without_scrolling_to_the_end(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('.composer{position:sticky;bottom:0', $css);
        // `overflow:hidden` على البطاقة يصنع حاوية تمرير فيُبطل الالتصاق — `clip` يقصّ بلا ذلك
        $this->assertStringContainsString('.card:has(.composer){overflow:clip}', $css);
    }
}
