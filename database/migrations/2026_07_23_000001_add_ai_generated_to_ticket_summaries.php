<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * علَم يميّز الملخّص المُولَّد فعلياً بالذكاء الاصطناعي عن القالب الاحتياطي —
 * يمنع اعتماد/إرسال قالب أجوف للعميل (يُضبط true فقط عند نجاح التحليل الحقيقي).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_summaries', function (Blueprint $table) {
            $table->boolean('ai_generated')->default(false)->after('key_points');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_summaries', function (Blueprint $table) {
            $table->dropColumn('ai_generated');
        });
    }
};
