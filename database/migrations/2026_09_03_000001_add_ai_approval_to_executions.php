<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اعتماد تحليل طلب التنفيذ قبل وصوله العميل — نظير `consults.summary_approved_*`.
 *
 * كان `ExecService::applyAnalysis` يسجّل القيد «يتطلّب مراجعة» صراحةً (بتعليقٍ يقول
 * «لا اعتماد آليّ لمخرجٍ يغيّر مسار ملفّ قانونيّ»)، ثم يكتب `ai_summary` و`ai_missing`
 * و`ai_procedures` في الملفّ، و`Execution::toFlowCard` يمرّرها إلى بطاقة العميل في
 * الطلب نفسه. أي أن الوسم يقول «انتظروا مراجعة» والمخرج قد وصل صاحبَه.
 *
 * و`ai_success` تعني **أن النموذج أعاد JSON صالحاً** لا أكثر: لا استرجاع ولا مطابقة
 * سند ولا مرورَ إنسان. فالنواقص التي يُطالَب بها العميل والإجراءات التي يُبنى عليها
 * توقّعُه كلّها من مخرجٍ لم يقرأه محامٍ.
 *
 * الحقلان إضافيّان: `null` = لم يُعتمد، وهي حال كل الصفوف القائمة — أي تُحجب
 * التحليلات الأربعة القائمة حتى يعتمدها محامٍ، وهو قرار المالك.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->timestamp('ai_approved_at')->nullable()->after('ai_procedures');
            $table->foreignId('ai_approved_by')->nullable()->after('ai_approved_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_approved_by');
            $table->dropColumn('ai_approved_at');
        });
    }
};
