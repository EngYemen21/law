<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حجب رسالةٍ عن العميل حتى يعتمدها محامٍ — لمسودّة اللائحة تحديداً.
 *
 * `case.pleading` مخرجٌ مصنَّف `high` يُنشَر **رسالةً** في محادثة القضية بعد سداد
 * الأتعاب (`CaseFee:255` ⇒ `DraftCasePleadingJob`). والعروض الثلاثة — عميل
 * (`CaseController:90`) ومحامٍ (`Lawyer\CaseController:49`) وموظّف
 * (`Employee\CaseController:113`) — تستعمل **الترشيح نفسه** `who != 'note'`، فلا
 * فاصل بين ما يراه المكتب وما يراه العميل. ووثيقةٌ قضائيّة رسميّة قد تحمل وسم
 * «لا سند نظاميّ مُتحقَّق» تصل صاحب القضية قبل أن يقرأها محامٍ.
 *
 * **ولماذا «محجوبة» لا «مُطلَقة»:** في `app/` ستّة وعشرون موضعاً تُنشئ رسائل قضية.
 * عمودٌ يعني «مُطلَقة» يقتضي أن يتذكّره كلٌّ منها، وأيّ موضعٍ ينساه تختفي رسالته
 * عن العميل صامتةً. أمّا هذا فتقصيره `null` = ظاهرة، ولا يمسّه إلّا من يقصد الحجب.
 *
 * والتعبئة الرجعيّة تحجب المسودّات القائمة — وهو قرار المالك: لا مخرج ذكاءٍ يصل
 * العميل قبل اعتماد محامٍ، ويُطلقها `AiReviewOutcome::releaseCasePleading`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_messages', function (Blueprint $table) {
            $table->timestamp('withheld_at')->nullable()->after('body');
        });

        DB::table('case_messages')
            ->where('role', 'مسودة اللائحة')
            ->update(['withheld_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('case_messages', function (Blueprint $table) {
            $table->dropColumn('withheld_at');
        });
    }
};
