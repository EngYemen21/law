<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * تأجيل الأعمال الثقيلة (نداءات الذكاء الاصطناعي) إلى ما بعد إرسال الاستجابة للمتصفح —
 * يمنع تجاوز مهلة تنفيذ الويب (30 ثانية) ويعيد الرد فوراً، وتصل النتائج للواجهة
 * عبر البث اللحظي القائم (Reverb). في الطرفية والاختبارات يُنفَّذ فوراً (سلوك حتمي).
 */
class AfterResponse
{
    public static function defer(\Closure $work): void
    {
        if (app()->runningInConsole()) {
            $work();

            return;
        }

        app()->terminating(function () use ($work) {
            @set_time_limit(300); // مهلة الويب لا تكفي سلسلة مزوّدي AI وإعادة محاولاتها

            try {
                $work();
            } catch (\Throwable $e) {
                Log::warning('AfterResponse deferred work failed: '.$e->getMessage());
            }
        });
    }
}
