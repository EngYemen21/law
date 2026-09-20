<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إزالة تكامل تقويم Google بقرار المالك (2026-09-20).
 *
 * العمود لم يعد له كاتب ولا قارئ بعد حذف `GoogleCalendarService`، وبقاؤه يوحي بمزامنة
 * قائمة. المهاجرة الأصليّة (2026_08_27_000002) تبقى كما هي — المهاجرات تراكميّة —
 * و`down()` هنا يعيد العمود بنفس شكلها كي يُسترجَع التكامل إن نُقض القرار.
 */
return new class extends Migration
{
    /** الجداول الثلاثة التي حملت معرّف حدث جوجل. */
    private const TABLES = ['consults', 'meetings', 'appointments'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'google_event_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('google_event_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'google_event_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('google_event_id')->nullable();
                });
            }
        }
    }
};
