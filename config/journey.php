<?php

return [
    /*
     * حارس كتابة حالات الرحلة خارج `Workflow` (`App\Domain\Journey\StateWriteGuard`).
     * off | record | throw — الاختبارات تضبطه في phpunit.xml.
     */
    'guard' => env('JOURNEY_GUARD', 'off'),

    /*
     * **جردٌ لا إعفاء:** في وضع `record` يُسجَّل الكتّاب المدرجون في `LEGACY_WRITERS` أيضاً
     * بوسم `[legacy]` — فتُعرف القائمة من الدليل: من ما زال يكتب، ومن صار بنده بائتاً.
     * لا أثر له في `throw` ولا `off`.
     */
    'record_legacy' => (bool) env('JOURNEY_RECORD_LEGACY', false),
];
