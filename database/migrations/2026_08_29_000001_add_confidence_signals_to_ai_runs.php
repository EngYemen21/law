<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الإشارات التي اشتُقّت منها درجة الثقة — بجوار `confidence` لا بدلاً عنها.
 *
 * درجةٌ مجرّدة (45 أو 80) بلا أساس معلن هي بعينها صنف البيانات غير المحقّقة الذي
 * أُزيل في P0 وP1. تخزين الإشارات يجعل الدرجة **قابلة للتدقيق**: يُعرف أن الـ45
 * جاءت من «لم يُقرأ أي مستند» لا من حكمٍ غامض، فيقرّر المراجع على أساس.
 *
 * `null` حين لا قياس (احتياطيّ/فشل) — تماماً كـ`confidence`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_runs') && ! Schema::hasColumn('ai_runs', 'confidence_signals')) {
            Schema::table('ai_runs', function (Blueprint $table) {
                $table->json('confidence_signals')->nullable()->after('confidence');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_runs') && Schema::hasColumn('ai_runs', 'confidence_signals')) {
            Schema::table('ai_runs', function (Blueprint $table) {
                $table->dropColumn('confidence_signals');
            });
        }
    }
};
