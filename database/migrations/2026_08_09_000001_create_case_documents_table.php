<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// مستندات ملف القضية — يرفعها العميل أو المحامي (أدلة/عقود/مذكرات) وتُحفظ ضمن ملف القضية للأرشفة والتقرير.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->string('name');                                // الاسم الأصلي للملف
            $table->string('path');                                // المسار الفعلي على القرص
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->nullable();           // بالبايت
            $table->string('uploaded_by')->default('client');      // client | lawyer
            $table->string('status')->default('مرفق');             // مرفق (لاحقاً: قيد الفحص/ملخّص عند إضافة التحليل الذكي)
            $table->string('doc_type')->nullable();                // نوع المستند (يُملأ عند التحليل الذكي لاحقاً)
            $table->text('summary')->nullable();                   // ملخّص المحتوى (يُملأ عند التحليل الذكي لاحقاً)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_documents');
    }
};
