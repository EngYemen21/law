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
