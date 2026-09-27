<?php

return [
    /*
     * حارس كتابة حالات الرحلة خارج `Workflow` (`App\Domain\Journey\StateWriteGuard`).
     * off | record | throw — الاختبارات تضبطه في phpunit.xml (`throw`).
     *
     * **الافتراضيّ `record` لا `off`:** كانت الكتابة الخاطفة للحالة خارج المحرّك تُرصد في الاختبارات
     * وحدها، وتمرّ في الإنتاج صامتة. `record` لا يرفض شيئاً — يسجّل الكاتب في
     * `storage/logs/journey-writers.log`، فيُرى من الخادم ما لم تغطّه الاختبارات.
     */
    'guard' => env('JOURNEY_GUARD', 'record'),

    /*
     * **جردٌ لا إعفاء:** في وضع `record` يُسجَّل الكتّاب المدرجون في `LEGACY_WRITERS` أيضاً
     * بوسم `[legacy]` — فتُعرف القائمة من الدليل: من ما زال يكتب، ومن صار بنده بائتاً.
     * لا أثر له في `throw` ولا `off`.
     */
    'record_legacy' => (bool) env('JOURNEY_RECORD_LEGACY', false),
];
