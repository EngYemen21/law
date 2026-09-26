<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **قائمة المستندات المطلوبة لكلّ قسمٍ قانونيّ** (قرار المالك 2026-09-26).
 *
 * كانت القائمة ثابتةً في الشيفرة (`ServiceDocs`) مفتاحُها سبعة عشر نوعَ قضيّةٍ قديماً، والتذاكر اليوم
 * تحمل أسماء خدمات الكتالوج — فلا يطابق إلّا نوعٌ واحد، وتسقط كلّ تذكرةٍ تقريباً إلى القائمة العامّة.
 * فصارت القائمة جزءاً من الكتالوج: لكلّ قسمٍ بنودُه، ولكلّ بندٍ «إلزاميّ» أو «اختياريّ»، وتحرّرها
 * شاشة «الأقسام والخدمات».
 *
 * - `cascadeOnDelete`: البند جزءٌ من قسمه لا يعيش بعده (والأقسام لا تُحذف أصلاً — تُوقَف).
 * - `unique(legal_department_id, name)`: شبكة أمانٍ خلف حارس المحرّر (يقارن بعد توحيد الصياغة).
 * - إضافةٌ خالصة: لا تُمسّ بيانات قائمة، و`down()` يُسقط الجدول وحده. الزرع بأمرٍ مستقلّ
 *   (`catalogue:seed-documents`) كي يُراجَع بـ`--dry-run` قبل الكتابة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_department_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('legal_department_id')->constrained('legal_departments')->cascadeOnDelete();
            $t->string('name', 160);
            $t->boolean('required')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();

            $t->unique(['legal_department_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_department_documents');
    }
};
