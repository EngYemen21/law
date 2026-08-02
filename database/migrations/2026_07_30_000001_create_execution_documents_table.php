<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// مستندات مطلوبة من العميل على طلب تنفيذ (يطلبها قسم التنفيذ ويرفعها العميل) — نظير exDocPanel
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->string('label');                              // اسم المستند المطلوب
            $table->string('status')->default('مطلوب');           // مطلوب | مرفوع | مقبول | مرفوض
            $table->string('path')->nullable();                   // المسار الفعلي بعد الرفع
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->nullable();          // بالبايت
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_documents');
    }
};
