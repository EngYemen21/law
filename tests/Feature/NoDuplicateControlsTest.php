<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **فعلٌ واحد من موضعٍ واحد** (جولة التبويبات، قرار المالك 2026-09-28): كانت صفحاتٌ تعرض شريطَي فلترةٍ
 * يفعلان الشيء نفسه، أو بطاقاتِ أرقامٍ تفلتر كشريط التبويبات، أو زرَّ حجزٍ في رأس صفحاتٍ لا تخصّه.
 */
class NoDuplicateControlsTest extends TestCase
{
    private function page(string $path): string
    {
        return (string) file_get_contents(resource_path("js/pages/{$path}.tsx"));
    }

    public function test_admin_tickets_and_clients_filter_from_the_table_card_only(): void
    {
        foreach (['admin/tickets', 'admin/clients'] as $page) {
            $src = $this->page($page);
            $this->assertStringNotContainsString('hero-cta', $src, "{$page}: الفلترة من أزرار بطاقة الجدول وحدها");
            $this->assertStringContainsString("setStatus('');", $src);
        }
    }

    public function test_lawyer_ticket_counters_are_display_only(): void
    {
        $this->assertStringContainsString('<StatRow items={statItems} />', $this->page('lawyer/tickets'));
    }

    public function test_client_pages_book_from_the_dashboard_and_consults_only(): void
    {
        foreach (['tickets', 'cases', 'meetings'] as $page) {
            $src = $this->page($page);
            $hero = substr($src, (int) strpos($src, 'hero-cta'), 900);
            $this->assertStringNotContainsString("router.visit('/book')", $hero, "{$page}: لا زرّ حجزٍ في رأس الصفحة");
        }
        $this->assertStringContainsString("router.visit('/book')", $this->page('myconsults'));
    }

    public function test_the_workload_card_links_to_distribution_not_a_second_staff_button(): void
    {
        $src = $this->page('admin/dashboard');
        $this->assertSame(1, substr_count($src, '/> إدارة الطاقم'), 'زرّ الطاقم مرّةً واحدة في رأس الصفحة');
        $this->assertStringContainsString("router.visit('/admin/distribute')} type=\"button\">\n              توزيع الأعمال", $src);
    }
}
