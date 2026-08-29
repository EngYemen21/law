<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مصدر تحليل الاستشارة بجوار `ai_done` — نظير ما جرى لطلبات التنفيذ.
 *
 * `Staff\ConsultController::analyze` كان يضبط `ai_done = true` بلا شرط، ويكتب في سجلّ
 * التدقيق «تحليل الفريق القانوني: — ← اكتمل» حتى حين يعود القالب الاحتياطيّ. فنصّ
 * الاحتياطيّ أمين («تعذّر إعداد التحليل الذكي») لكن الحالة والسجلّ يكذّبانه.
 * الصفوف القائمة تبقى `NULL` = مصدر غير معروف.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('consults') && ! Schema::hasColumn('consults', 'ai_source')) {
            Schema::table('consults', function (Blueprint $table) {
                $table->string('ai_source')->nullable()->after('ai_done');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('consults') && Schema::hasColumn('consults', 'ai_source')) {
            Schema::table('consults', function (Blueprint $table) {
                $table->dropColumn('ai_source');
            });
        }
    }
};
