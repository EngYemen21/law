<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **الدراسة تُعاد جدولتها ولا تُملأ بقالب** (قرار المالك 2026-09-12).
 *
 * حين يتعذّر الذكاء (حدّ معدّل، انقطاع، مهلة) كانت المهمّة تموت في `failed_jobs` فيبقى
 * الملفّ «قيد الإعداد» أبداً، أو يُكتب قالبٌ حتميّ يزعم أهليّة السند بلا قراءة مستند.
 * فيُعدّ هنا ما يلزم لإعادة المحاولة: عدد المحاولات وموعد آخرها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->unsignedTinyInteger('ai_attempts')->default(0)->after('ai_study');
            $table->timestamp('ai_attempted_at')->nullable()->after('ai_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropColumn(['ai_attempts', 'ai_attempted_at']);
        });
    }
};
