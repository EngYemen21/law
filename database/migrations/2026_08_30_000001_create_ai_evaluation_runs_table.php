<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ تشغيلات مجموعة التقييم — **خطّ الأساس**.
 *
 * كانت النتيجة تُحفَظ في مفتاح `Setting` واحد يُستبدَل عند كل تشغيل، فلا يبقى ما
 * يُقارَن به: تتراجع مهمّةٌ من 100% إلى 60% ولا يُلاحَظ. والخطة تفرض «مقارنة A/B مع
 * الإصدار السابق» و«عدم تراجع في فئات المخاطر العالية» — وكلاهما مستحيل بلا ذاكرة.
 *
 * وتُحفَظ **إصدارات التعليمات والنماذج** مع النتيجة: مقارنةٌ لا تعرف ما الذي تغيّر
 * تقول «تراجَعَ» ولا تقول «لماذا»، فتصير ملاحظةً لا قراراً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->boolean('live')->default(false);
            $table->string('triggered_by')->nullable();

            // `null` = لم تُقَس (تشغيل جافّ، أو سعر نموذج مجهول) — لا صفر يوهم بالمجّانيّة
            $table->decimal('cost', 12, 6)->nullable();
            $table->unsignedInteger('live_calls')->default(0);

            $table->json('results');
            $table->json('prompt_versions')->nullable();
            $table->json('models')->nullable();

            $table->timestamps();
            $table->index(['created_at', 'live']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluation_runs');
    }
};
