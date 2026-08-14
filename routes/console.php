<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// إطلاق روابط الجلسات المرئية قبل الموعد بـ5 دقائق وتفعيل الدخول (يحتاج `schedule:run` عبر cron)
Schedule::command('zoom:release-links')->everyMinute()->withoutOverlapping();

// جلب ملخّص AI Companion من Zoom للجلسات المنتهية (غير متزامن — يجهز بعد دقائق)
Schedule::command('zoom:pull-summaries')->everyFiveMinutes()->withoutOverlapping();

// تذكير بالاجتماعات القادمة عبر البريد قبل الموعد بـ60د (يحتاج `schedule:run` عبر cron)
Schedule::command('meetings:send-reminders')->everyMinute()->withoutOverlapping();

// تذكير بمواعيد الاستشارات عبر البريد (طبقتا: قبل 24 ساعة، وقبل ساعة) (يحتاج `schedule:run` عبر cron)
Schedule::command('consults:send-reminders')->everyMinute()->withoutOverlapping();

// تذكير بجلسات القضايا — إشعار داخلي + بريد (طبقتا: قبل 24 ساعة، وقبل ساعة) (يحتاج `schedule:run` عبر cron)
Schedule::command('hearings:send-reminders')->everyMinute()->withoutOverlapping();

// حسم وتصفية الاجتماعات والدعوات القديمة غير المنعقدة تلقائياً
Schedule::command('zoom:auto-close-missed')->everyFifteenMinutes()->withoutOverlapping();

// تذكير بسداد فواتير أتعاب التنفيذ المستحقة
Schedule::command('exec:send-payment-reminders')->everyThirtyMinutes()->withoutOverlapping();

