<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **القيمة الافتراضيّة تُعرض تحت كلّ حقل** في «إعدادات النظام» (قرار المالك 2026-10-01) — كانت تلميحاً على
 * زرّ «إعادة إلى الافتراض» وحده. الحقل يُرسم من دالّةٍ واحدة (`renderField`)، فسطرٌ واحد فيها يشمل كلّ الحقول.
 */
class SettingsDefaultShownTest extends TestCase
{
    public function test_every_field_renders_its_default_under_the_hint(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/admin/settings.tsx'));

        $this->assertStringContainsString('القيمة الافتراضيّة: <b', $page);
        $this->assertStringContainsString('{defaultText(field)}', $page);
        $this->assertSame(1, substr_count($page, 'const renderField'), 'الحقول كلّها تُرسم من دالّةٍ واحدة');
    }
}
