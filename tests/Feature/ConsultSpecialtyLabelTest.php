<?php

namespace Tests\Feature;

use App\Models\Consult;
use App\Support\Specialties;
use Tests\TestCase;

/**
 * **خانة «التخصص» في رحلة الاستشارة — حكم الخادم.**
 *
 * كانت الواجهة تقارن `c.specialty !== 'كل الأقسام'` بنصٍّ مكتوبٍ حرفيّاً خارج ثابته، فتغييرُ صياغته
 * في الخادم يُظهر «كل الأقسام» تخصّصاً بصمت. صار `Consult::specialtyLabel` يحكم بالثابت الواحد.
 */
class ConsultSpecialtyLabelTest extends TestCase
{
    public function test_the_label_is_the_specialty_or_falls_back_to_the_type(): void
    {
        $this->assertSame('الأحوال الشخصية', Consult::specialtyLabel('الأحوال الشخصية', 'استشارة'));
        $this->assertSame('استشارة', Consult::specialtyLabel(Specialties::ALL_DEPARTMENTS, 'استشارة'));
        $this->assertSame('استشارة', Consult::specialtyLabel('', 'استشارة'));
        $this->assertSame('استشارة', Consult::specialtyLabel(null, 'استشارة'));
    }

    public function test_the_card_carries_it_and_the_ui_reads_it(): void
    {
        $consult = new Consult(['specialty' => Specialties::ALL_DEPARTMENTS, 'type' => 'استشارة عامة']);
        $this->assertSame('استشارة عامة', Consult::specialtyLabel($consult->specialty, $consult->type));

        $ui = (string) file_get_contents(resource_path('js/lib/consult-ui.tsx'));
        $this->assertStringNotContainsString("'كل الأقسام'", $ui);
        $this->assertStringContainsString('c.specialtyLabel', $ui);
    }
}
