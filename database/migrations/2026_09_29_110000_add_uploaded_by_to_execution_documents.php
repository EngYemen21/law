<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **مَن رفع مستند التنفيذ** — كان بلا عمود، فتعرض شاشة «المستندات» كلّ مرفقات التنفيذ «صادرةً إليك»
 * بوسم «قرار 34/46» ولو رفعها العميل بنفسه (ثبت باختبار 2026-09-29).
 *
 * الافتراض `client`: كلّ مسارات الرفع اليوم للعميل (التقديم، الإرفاق من المحادثة، ملء خانةٍ طلبها المكتب)،
 * فالصفوف القائمة كذلك. والاستثناء الوحيد نسخُ مرفقات التذكرة عند تحويلها تنفيذاً — يكتب `staff` لما
 * أرفقه المكتب (`ExecutionCreation::migrateTicketDocuments`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_documents', function (Blueprint $table) {
            $table->string('uploaded_by', 20)->default('client')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('execution_documents', function (Blueprint $table) {
            $table->dropColumn('uploaded_by');
        });
    }
};
