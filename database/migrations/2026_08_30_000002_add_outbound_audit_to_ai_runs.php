<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دليل تقليل البيانات على كل نداء — مطلب P2.
 *
 * الخطة تفرض «أقلّية البيانات المرسلة للمزوّد الخارجي: **يثبتها سجلّ حقول لكل مهمّة**».
 * كان التمويه يعمل (`AiContextBuilder`) بلا أثرٍ باقٍ عليه، فالمدقّق يُطالَب بتصديق
 * الشيفرة لا بقراءة سجلّ. وهذا العمود هو الأثر: عدد المعرّفات المموَّهة وحجم الحمولة
 * التي غادرت الخادم فعلاً.
 *
 * **لا يحوي محتوى**: أعداداً وحجماً فقط — دليلٌ لا نسخةٌ ثانية من البيانات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->json('outbound_audit')->nullable()->after('confidence_signals');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn('outbound_audit');
        });
    }
};
