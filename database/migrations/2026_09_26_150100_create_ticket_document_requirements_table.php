<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **أيّ بندٍ من قائمة القسم يستوفيه كلّ مستندٍ مرفق** — أساس «النواقص» لكلّ تذكرة (قرار المالك 2026-09-26).
 *
 * لماذا جدولٌ وسيط لا عمودٌ في `ticket_documents`: ملفٌّ واحد قد يستوفي بندين (عقدٌ ومراسلاته في
 * PDF واحد)، ولكلّ مطابقةٍ مصدرُها — حكمُ الذكاء أو تأكيدُ موظّف — فيُحفظ المصدر للبند لا للملفّ.
 *
 * - `legal_department_document_id` (nullOnDelete): المطابقة تتبع البند بمعرّفه فتبقى بعد إعادة تسميته؛
 *   و`requirement` نصُّ البند وقت المطابقة — لبنود القائمة العامّة (بلا صفّ) ولما حُذف بندُه.
 * - `checked_by`: `ai` | `staff` (`App\Enums\RequirementCheck`).
 * - `ticket_documents.requirements_checked_at`: متى فُحص الملفّ مقابل القائمة (آلياً أو يدوياً).
 *   فارغٌ ⇒ «لم يُتحقّق» — لا يُعدّ شيءٌ مستوفىً بافتراض.
 * - إضافةٌ خالصة، و`down()` يُسقط الجدول والعمود وحدهما.
 * - **أسماء القيود صريحةٌ قصيرة:** الاسم المولَّد `ticket_document_requirements_legal_department_document_id_foreign`
 *   ٦٦ حرفاً، وMySQL يقبل ٦٤ — فسقطت المهاجرة على قاعدة التطوير ونجحت الاختبارات (SQLite بلا حدّ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_document_requirements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_document_id')->constrained('ticket_documents', 'id', 'tdr_ticket_document_fk')->cascadeOnDelete();
            $t->foreignId('legal_department_document_id')->nullable()->constrained('legal_department_documents', 'id', 'tdr_department_document_fk')->nullOnDelete();
            $t->string('requirement', 160);
            $t->string('checked_by', 10);
            $t->foreignId('checked_by_user_id')->nullable()->constrained('users', 'id', 'tdr_checked_by_user_fk')->nullOnDelete();
            $t->timestamps();

            $t->unique(['ticket_document_id', 'requirement'], 'tdr_document_requirement_unique');
        });

        Schema::table('ticket_documents', function (Blueprint $t) {
            $t->timestamp('requirements_checked_at')->nullable()->after('summary_approved');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_documents', function (Blueprint $t) {
            $t->dropColumn('requirements_checked_at');
        });

        Schema::dropIfExists('ticket_document_requirements');
    }
};
