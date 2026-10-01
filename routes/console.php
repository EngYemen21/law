<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// إطلاق روابط الجلسات المرئية عند فتح الدخول (`session_join_opens_minutes`، ربع ساعة) وتفعيل الدخول ورسالة الرابط للعميل (يحتاج `schedule:run` عبر cron)
Schedule::command('zoom:release-links')->everyMinute()->withoutOverlapping();

// جلب ملخّص AI Companion من Zoom للجلسات المنتهية (غير متزامن — يجهز بعد دقائق)
Schedule::command('zoom:pull-summaries')->everyFiveMinutes()->withoutOverlapping();

// استكمال روابط التسجيل/الصوت والنص التفريغي الناقصة (شبكة أمان لويبهوك التسجيلات)
Schedule::command('zoom:pull-recordings')->everyFifteenMinutes()->withoutOverlapping();

// تذكير بالاجتماعات القادمة عبر البريد قبل الموعد بـ60د (يحتاج `schedule:run` عبر cron)
Schedule::command('meetings:send-reminders')->everyMinute()->withoutOverlapping();

// تذكير بمواعيد الاستشارات (بريد ثمّ رسالة نصّيّة — المدّتان من الإعدادات `consult_reminder_*`) (يحتاج `schedule:run` عبر cron)
Schedule::command('consults:send-reminders')->everyMinute()->withoutOverlapping();

// تذكير بجلسات القضايا — إشعار داخلي + بريد (طبقتا: قبل 24 ساعة، وقبل ساعة) (يحتاج `schedule:run` عبر cron)
Schedule::command('hearings:send-reminders')->everyMinute()->withoutOverlapping();

// حسم وتصفية الاجتماعات والدعوات القديمة غير المنعقدة تلقائياً
Schedule::command('zoom:auto-close-missed')->everyFifteenMinutes()->withoutOverlapping();

// حسم الاستشارات الفائتة (بانتظار الجلسة + فات موعدها 12 ساعة ⇒ لم يحضر)
Schedule::command('consults:auto-close-missed')->everyFifteenMinutes()->withoutOverlapping();

// شبكة النسيان: جلسةٌ (استشارة أو اجتماع) بدأت ولم يُنهها أحد بعد `session_stale_minutes` يُنبَّه بها
// الطاقم مرّةً واحدة **وتُنهى** في النظام وتُغلق غرفتها في Zoom (قرار المالك 2026-09-26 الأخير)
Schedule::command('sessions:close-stale')->everyFifteenMinutes()->withoutOverlapping();

// وسم جلسات القضايا الفائتة (+24 ساعة) «بانتظار تسجيل النتيجة» وتنبيه محاميها
Schedule::command('hearings:auto-lapse')->hourly()->withoutOverlapping();

// تذكير بسداد فواتير أتعاب التنفيذ المستحقة
Schedule::command('exec:send-payment-reminders')->everyThirtyMinutes()->withoutOverlapping();

// تنبيه المكتب بانقضاء مهلة الوفاء (أمر التنفيذ) على ملفّات بلا إجراءات عدم وفاء
Schedule::command('exec:send-paydue-alerts')->hourly()->withoutOverlapping();

// إعادة جدولة دراسة التنفيذ المتعذّرة — لا قالب يملأ فراغ الذكاء، والمحاولة تُعاد
Schedule::command('exec:retry-study')->hourly()->withoutOverlapping();

// حسم المواعيد التي فات وقتها — الكيان الوحيد الذي كانت حالته تُشتقّ ولا تُكتب
Schedule::command('appointments:auto-lapse')->everyFifteenMinutes()->withoutOverlapping();

// شبكة أمان: تذاكر بقيت بلا محامٍ (فُتحت قبل الميزة · فشلت وظيفة التصعيد · أُلغي إسنادها)
Schedule::command('tickets:escalate-unassigned')->everyFifteenMinutes()->withoutOverlapping();

// تقرير حوكمة الذكاء الأسبوعيّ — يُحفظ ملفّاً مؤرَّخاً.
//
// كان `ai:report` غير مجدول رغم أن توثيقه يَعِد بـ«تشغيلٍ مجدول يصل بالبريد أو
// يُحفظ ملفّاً»، فلا تُقرأ مؤشّرات الحوكمة ولا أسباب الرفض ولا إنذارات الميزانيّة
// إلّا إن فتح مسؤولٌ الشاشة مصادفةً — ويُكتشَف تجاوزُ السقف أو تراجعُ الجودة بالحظّ.
// (ولا يُرسَل بريد: مُستقبِلُه قرارُ المكتب لا افتراضُ الشيفرة.)
Schedule::command('ai:report --days=7')
    ->weeklyOn(7, '07:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/ai-governance.log'));
