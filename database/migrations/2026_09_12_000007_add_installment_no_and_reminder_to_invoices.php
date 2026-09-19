<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فاتورةٌ تعرف موضعها من الخطّة، وتذكيرٌ يُختم عليها لا على الملفّ.
 *
 * `installment_no`: ملفّ تنفيذٍ صار يحمل فواتير من نوعين — دفعات خطّة، وفواتير أتعابٍ عن
 * كلّ تحصيل في النموذج النسبيّ. واشتقاق «كم دفعةً سُدّدت» من عدّ المدفوع يصحّ فقط إن أمكن
 * فصل النوعين، وإلا قدّمت فاتورةُ تحصيلٍ خطّةَ تقسيطٍ بلا دفعة.
 *
 * `reminder_sent_at`: كان الختم على الطلب (`executions.payment_reminder_sent_at`) فيكفي
 * فاتورةً واحدة. مع ثلاث دفعاتٍ وفواتير تحصيلٍ متعدّدة يلزم ختمٌ لكلّ فاتورة، وإلا لوحق
 * أوّلُها ولم يُلاحَق ما بعده. والعمود القديم يبقى للصفوف السابقة ولا يُقرأ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedTinyInteger('installment_no')->nullable()->after('exec_id'); // 1..3 — null: ليست دفعة خطّة
            $table->timestamp('reminder_sent_at')->nullable()->after('due_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['installment_no', 'reminder_sent_at']);
        });
    }
};
