<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قرارات الجلسة تُحفظ **اقتراحات** قبل أن تصير مهامّ — مطلب المرحلة P3.
 *
 * كان مسارا ملخّص Zoom (الاستشارة والاجتماع) يُنشئان مهامّ لدى المحامي تلقائياً من
 * نصٍّ استخرجه نموذج: أي أن مخرج نموذج يُنشئ التزاماً على إنسان بلا أن يقرّه أحد.
 * الاقتراح يُحفظ هنا وينتظر زرّ الاعتماد القائم أصلاً (`createTasks`).
 *
 * `tasks_created` يبقى كما هو: مَن اعتمد سابقاً لا تتغيّر حالته.
 */
return new class extends Migration
{
    private const TABLES = ['consults', 'meetings'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'suggested_tasks')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->json('suggested_tasks')->nullable()->after('tasks_created');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'suggested_tasks')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('suggested_tasks');
            });
        }
    }
};
