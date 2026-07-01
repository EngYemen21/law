<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lawyer_id')->nullable()->constrained('users')->nullOnDelete();
            // الملخص الرباعي الذي يجهّزه «الفريق القانوني» (الذكاء الاصطناعي) للمستشار
            $table->text('case_summary')->nullable();        // تلخيص القضية
            $table->text('attachments_summary')->nullable(); // تلخيص المرفقات
            $table->text('facts')->nullable();               // تجهيز الوقائع
            $table->text('key_points')->nullable();          // النقاط المهمة
            // دورة حياة الملخص: awaiting_lawyer → approved
            $table->string('status', 24)->default('awaiting_lawyer');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_summaries');
    }
};
