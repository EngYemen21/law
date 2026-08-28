<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توسيع أعمدة روابط Zoom — وقاية من نفس صنف عطل `last_message` الذي أسقط فتح التذكرة.
 *
 * القيم هنا **تأتي من Zoom لا من عندنا**، فطولها خارج سيطرتنا:
 * - `host_link` = `start_url` ويحمل رمز ZAK (‏JWT) — يتراوح عملياً بين 800 و1500 حرف،
 *   و`varchar(1000)` رهان لا ضمان.
 * - `recording_url` = رابط التسجيل من PullZoomRecordings، وقد يحمل `access_token` فيتجاوز
 *   `varchar(255)` بسهولة.
 *
 * ومع `'strict' => true` في اتصال mysql ترمي القاعدة SQLSTATE[22001] بدل أن تقصّ، فيفشل
 * حفظ الاجتماع/التسجيل كلّه — لا مجرّد بتر الرابط. ولم يكن ليظهر في الحزمة قط: كانت تعمل
 * على sqlite الذي لا يفرض عرض varchar (نفس سبب خفاء عطل last_message).
 *
 * `meet_link` (‏`join_url`) يبقى varchar(500): رابط الانضمام قصير ثابت البنية (~120 حرفاً
 * مع pwd) ولا سبب لتوسيعه.
 *
 * لا فهارس على هذه الأعمدة (فُحص) فالتحويل إلى TEXT بلا أثر جانبي.
 * غير مدمّرة؛ و`down()` يعيد العروض السابقة (يبتر ما تجاوزها — استعادة النسخة الاحتياطية
 * هي الرجوع الآمن).
 */
return new class extends Migration
{
    /** [الجدول => [العمود => العرض السابق]] */
    private const COLUMNS = [
        'meetings' => ['host_link' => 1000, 'recording_url' => 255],
        'consults' => ['host_link' => 1000, 'recording_url' => 255],
        'meet_requests' => ['host_link' => 1000],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table, $cols) {
                foreach ($cols as $col => $was) {
                    if (Schema::hasColumn($table, $col)) {
                        $t->text($col)->nullable()->change();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table, $cols) {
                foreach ($cols as $col => $was) {
                    if (Schema::hasColumn($table, $col)) {
                        $t->string($col, $was)->nullable()->change();
                    }
                }
            });
        }
    }
};
