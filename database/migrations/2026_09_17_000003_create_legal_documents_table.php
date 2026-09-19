<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول «محرر الصياغة القانونية» — مستقل تماماً عن جدول documents الحالي.
 *
 * documents = مرفقات العميل (PDF/صور) مرتبطة بالتذاكر.
 * legal_documents = مستندات منسّقة أنشأها المحامي/الإدارة/الموظف
 *                   عبر محرر WYSIWYG المدمج (TipTap).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');                              // عنوان المستند
            $table->string('type', 40)->default('free');          // lawsuit | memo | summary | contract | letter | free
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();  // المنشئ
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();  // ربط اختياري بتذكرة
            $table->unsignedBigInteger('case_id')->nullable();    // ربط اختياري بقضية
            $table->longText('content_html');                     // المحتوى HTML المنسّق (للعرض والتصدير)
            $table->json('content_json')->nullable();             // محتوى TipTap JSON (لإعادة التحرير)
            $table->string('status', 20)->default('draft');       // draft | review | approved | archived
            $table->json('metadata')->nullable();                 // بيانات إضافية (محكمة، أطراف، قالب مصدر)
            $table->json('header_config')->nullable();            // إعدادات الترويسة (شعار، اسم المكتب، عنوان)
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['ticket_id']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
