<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مصدر تحليل طلب التنفيذ بجوار `ai_done` — إضافة لا استبدال.
 *
 * `ai_done` يبقى كما هو للتوافق الخلفيّ (تستهلكه الواجهة وشروط الأزرار)، لكنه لم يعد
 * يُضبط إلا لتحليل فعليّ؛ و`ai_source` يحمل التمييز الصريح القابل للعرض والتدقيق.
 * الصفوف القائمة تُوسم `NULL` = «مصدر غير معروف» — لا تُدَّعى لها نجاحاً بأثر رجعيّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('executions') && ! Schema::hasColumn('executions', 'ai_source')) {
            Schema::table('executions', function (Blueprint $table) {
                $table->string('ai_source')->nullable()->after('ai_done');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('executions') && Schema::hasColumn('executions', 'ai_source')) {
            Schema::table('executions', function (Blueprint $table) {
                $table->dropColumn('ai_source');
            });
        }
    }
};
