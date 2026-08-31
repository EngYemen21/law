<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الطبقة الثالثة: مراجعة محامٍ لعيّنة **عمياء**.
 *
 * الخطة تفرض «عيّنة دوريّة (10–20 مخرجاً) يراجعها محامٍ **لا يعرف** أنها من الذكاء»،
 * ثم تُقارن نتيجته بحكم الطبقة الثانية — «واختلافهما يكشف عيباً في المعايير لا في
 * النموذج».
 *
 * لم تكن ثمّة آليّة لذلك: المطلوب مراجعةٌ عمياء والشاشة الوحيدة تعرض المصدر والنموذج
 * وإصدار التعليمة ودرجة الثقة. فالمحامي يعرف قبل أن يحكم، وحكمُه بعدها ليس أعمى.
 *
 * **العمى محفوظ في البيانات لا في العرض وحده:** `revealed_at` يفصل زمن الحكم عن زمن
 * الكشف، فيثبت السجلّ أن الحكم سبق معرفة المصدر — وإلّا صار «الأعمى» ادّعاءً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_blind_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_run_id')->constrained('ai_runs')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();

            // حكم المحامي — يُسجَّل قبل الكشف
            $table->string('verdict')->nullable();
            $table->string('reason')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('judged_at')->nullable();

            // متى كُشف له المصدر. `null` = لم يُكشف بعد، فالحكم ما زال أعمى
            $table->timestamp('revealed_at')->nullable();

            // حكم الطبقة الثانية وقت السحب — يُجمَّد كي لا تتغيّر المقارنة بتغيّر العتبة
            $table->string('machine_status');
            $table->unsignedTinyInteger('machine_confidence')->nullable();

            $table->timestamps();
            $table->unique(['ai_run_id', 'reviewer_id']);
            $table->index(['reviewer_id', 'judged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_blind_reviews');
    }
};
