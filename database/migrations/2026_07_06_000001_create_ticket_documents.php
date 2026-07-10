<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// مستندات التذكرة — تخزين المسار الفعلي + نتيجة الفحص الذكي (الارتباط بموضوع التذكرة)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('name');                          // اسم الملف الأصلي
            $table->string('path');                          // المسار على قرص التخزين
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->default(0);     // بالبايت
            $table->string('status', 40)->default('قيد الفحص'); // قيد الفحص/مرتبط/غير مرتبط/بحاجة لمراجعة يدوية
            $table->string('doc_type', 120)->nullable();     // نوع المستند كما فهمه الذكاء الاصطناعي
            $table->text('summary')->nullable();             // ملخص المحتوى
            $table->text('reason')->nullable();              // سبب الارتباط أو عدمه
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_documents');
    }
};
