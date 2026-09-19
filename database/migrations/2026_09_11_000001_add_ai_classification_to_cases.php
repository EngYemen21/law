<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **تصنيفُ القضيّة الذكيّ مقترحٌ ينتظر المراجعة — لا حكمٌ يُكتب في الملفّ.**
 *
 * سياسة `case.classify` «عالية» (`AiPolicyGate`): مراجعةٌ بشريّة إلزاميّة مهما كانت الثقة.
 * وكان `ClassifyConvertedCaseJob` يكتب النوع والقسم في القضيّة ويعيد كتابة رسالة «التحليل»
 * التي يراها العميل — فالمخرج يصل صاحبَه والقيدُ يقول «بانتظار المراجعة».
 *
 * فيُحفظ المقترح هنا `{type, department}`، ويطبّقه `AiReviewOutcome` عند القبول أو التعديل.
 * العمود إضافيّ و`null` لكلّ الصفوف القائمة: ما طُبّق سابقاً يبقى كما هو.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->json('ai_classification')->nullable()->after('department');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn('ai_classification');
        });
    }
};
