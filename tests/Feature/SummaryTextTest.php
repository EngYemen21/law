<?php

namespace Tests\Feature;

use App\Support\SummaryText;
use Tests\TestCase;

/**
 * **ملخّص الاستشارة فقراتٍ وقوائم في التقرير المطبوع — والنصّ مهرَّبٌ دائماً** (ملاحظة المالك 2026-09-27).
 */
class SummaryTextTest extends TestCase
{
    public function test_it_builds_paragraphs_lists_and_emphasis(): void
    {
        $html = SummaryText::html("### الخلاصة\nالمبلغ **150 ألف** مستحق.\nسطرٌ تالٍ.\n\n- البند الأول\n- البند *الثاني*\n\n1. أولاً\n٢. ثانياً\n---\nختام");

        $this->assertSame(
            '<p><b>الخلاصة</b></p><p>المبلغ <b>150 ألف</b> مستحق.<br>سطرٌ تالٍ.</p>'
            .'<ul><li>البند الأول</li><li>البند <i>الثاني</i></li></ul>'
            .'<ol><li>أولاً</li><li>ثانياً</li></ol><hr><p>ختام</p>',
            $html
        );
    }

    public function test_it_never_passes_html_through(): void
    {
        $html = SummaryText::html('<script>alert(1)</script> **<img src=x onerror=alert(1)>**');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_a_lone_star_stays_a_character(): void
    {
        $this->assertSame('<p>5 * 3 = 15</p>', SummaryText::html('5 * 3 = 15'));
    }
}
