<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **زرّ «نسخ السجلّ» في سجلّ انتقالات الرحلة** — كانت دالّة النسخ مكتوبةً بلا زرٍّ يناديها (جولة التبويبات).
 * الزرّ ينسخ ما يُعرض نفسه، ويُبلغ حين تمنع الحافظة.
 */
class JourneyCopyButtonTest extends TestCase
{
    public function test_the_dev_log_has_a_wired_copy_button_that_copies_what_is_shown(): void
    {
        $src = (string) file_get_contents(resource_path('js/pages/admin/journey-transitions.tsx'));

        $this->assertStringContainsString('onClick={copyPayload}', $src);
        $this->assertStringContainsString('writeText(devLogText(activeItem))', $src);
        $this->assertStringContainsString('{devLogText(activeItem)}', $src, 'المنسوخ هو المعروض');
        $this->assertStringContainsString('.catch(() => toast(', $src);
    }
}
