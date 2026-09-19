<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **مدخلات التسعير من الدراسة الذكيّة** — عمودٌ واحد لا ستّة.
 *
 * كان المحامي يسعّر ملفّ التنفيذ على ثلاثة حقول: ملخّصٌ ونواقصُ وإجراءاتٌ مقترحة —
 * فلا جاهزيّةَ سندٍ ولا درجةَ تعقيدٍ ولا عددَ إجراءاتٍ متوقَّعاً ولا مدّةً ولا مؤشّراتِ
 * تحصيلٍ ولا مخاطر. وهذه بعينها ما يُبنى عليه الرقم.
 *
 * ولماذا عمودٌ واحد بصيغة JSON: الحقول الستّة كلّها **مخرجُ نموذجٍ** يُقرأ معاً ويُعرض
 * معاً ولا يُستعلَم عنه ولا يُفهرَس ولا يُجمَع — فستّة أعمدة تعني ستّ مهاجرات لاحقة
 * كلّما زاد النموذج حقلاً، بلا مقابل. والأعمدة المستقلّة (`ai_summary` · `ai_missing`
 * · `ai_procedures`) تبقى كما هي: تقرؤها صفوفٌ قائمة وشيفرةٌ قائمة.
 *
 * `null` = لا دراسة بعد (كلّ الصفوف القائمة)، ويقرؤها `Execution::toFlowCard` قيماً
 * فارغة آمنة — فلا صفٌّ قديم ينكسر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->json('ai_study')->nullable()->after('ai_procedures');
        });
    }

    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropColumn('ai_study');
        });
    }
};
