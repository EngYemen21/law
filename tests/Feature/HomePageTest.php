<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فحصُ دخانٍ للصفحة الرئيسة.
 *
 * ورث المشروع هذا التأكيد من `tests/Feature/ExampleTest.php` المولّد بصيغة **Pest**،
 * وكان ذلك الملفّ يُجهض تحت PHPUnit **دفعتَه كاملةً** — فيضيع اثنا عشر ملفّاً بلا
 * نتيجة، ويبدو التشغيل ناجحاً وهو لم يفحصها. حُذف الملفّان، وبقي ما كانا يفحصانه
 * فعلاً هنا بالصيغة التي تعمل.
 */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_loads_for_a_guest(): void
    {
        $this->get(route('home'))->assertOk();
    }
}
