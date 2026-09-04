<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تحرير المحامي لملخّص الاستشارة قبل اعتماده — و«مصدر الملخّص» مفصولاً عن «مصدر التحليل».
 *
 * **لماذا التحرير:** «تعديل واعتماد» كان خياراً في قائمة صندوق المراجعة **بلا حقل
 * نصٍّ يستقبله** — يُسجَّل في `ai_runs.review_action` أن المحامي عدّل، والمنشور هو
 * نصّ النموذج حرفياً. فكان `humanEditRate` استفتاءً على نيّةٍ لا قياساً لعمل.
 * و`summary_ai_original` يحفظ ما كتبه النموذج قبل يد الإنسان، فيصير الفرق **مقيساً**.
 *
 * **ولماذا `summary_ai_source`:** كان `consults.ai_source` عموداً واحداً لمسارين —
 * يكتبه `ConsultController::analyze` من `consult.analyze`، ويدهسه `FinalizeConsultJob`
 * من `consult.summary`. والواجهة تقرأ `aiSource === 'fallback'` فتعنون «تعذّر التحليل
 * الذكيّ»: أي أن تعثّر الملخّص كان يُعيد وسم تحليلٍ سابق **نجح**. مصدران ⇒ عمودان.
 *
 * وكلّها إضافيّة: `null` = لم يُحرَّر / لم يُقَس، وهي حال كل الصفوف القائمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->text('summary_ai_original')->nullable()->after('summary');
            $table->timestamp('summary_edited_at')->nullable()->after('summary_approved_by');
            $table->foreignId('summary_edited_by')->nullable()->after('summary_edited_at')
                ->constrained('users')->nullOnDelete();
            $table->string('summary_ai_source', 32)->nullable()->after('summary_edited_by');
        });

        Schema::table('ai_runs', function (Blueprint $table) {
            // مسافة التحرير: عدد الحروف التي غيّرها الإنسان في المنشور عن مخرج النموذج.
            // `null` = لم تُقَس (قيدٌ لا مخرج نصّيّ له) — لا `0` الذي يعني «لم يُغيَّر شيء».
            $table->unsignedInteger('review_edit_distance')->nullable()->after('review_note');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn('review_edit_distance');
        });

        Schema::table('consults', function (Blueprint $table) {
            $table->dropConstrainedForeignId('summary_edited_by');
            $table->dropColumn(['summary_ai_original', 'summary_edited_at', 'summary_ai_source']);
        });
    }
};
