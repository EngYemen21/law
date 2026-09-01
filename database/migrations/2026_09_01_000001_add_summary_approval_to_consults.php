<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اعتماد ملخّص الاستشارة قبل وصوله العميل.
 *
 * كان الملخّص — رأيٌ قانونيّ مصنَّف `high` — يُكتب في `consults.summary` ويُشعَر به
 * العميل فوراً، ويُعرض له تحت شارة **«معتمد رسمياً»** بلا أن يمرّ به إنسان: لا
 * استرجاع ولا تحقّق ولا اعتماد. و`approveAnalysis` القائمة تعتمد تحليل **ما قبل**
 * الجلسة (`ai_class`/`ai_summary`) لا ملخّصها، فلم يكن للملخّص مسار اعتماد أصلاً.
 *
 * الحقلان إضافيّان بالكامل: `null` = لم يُعتمد بعد، وهي حال كل الصفوف القائمة.
 * والاستشارات التي سبق أن رأى عملاؤها ملخّصها تُعالَج بقرار المكتب لا بالهجرة —
 * ملءُ التاريخ آلياً هنا يعني ادّعاء اعتمادٍ لم يقع، وهو العطل نفسه في اتّجاه آخر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->timestamp('summary_approved_at')->nullable()->after('summary');
            $table->foreignId('summary_approved_by')->nullable()->after('summary_approved_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dropConstrainedForeignId('summary_approved_by');
            $table->dropColumn('summary_approved_at');
        });
    }
};
