<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// تدفّق طلب التنفيذ التجاريّ (10 مراحل، نظير EXEC_REQS في index (21).html): تقديم مستقلّ + تحليل + تسعير + عرض + سداد + إجراءات.
// عمود stage (nullable) يميّز طلبات التدفّق الجديد عن الطلبات القديمة (case→exec) التي تبقى بلا stage.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->unsignedTinyInteger('stage')->nullable()->after('status');   // 0..9 (فهرس EXEC_FLOW) — null = طلب قديم
            $table->string('sanad')->nullable()->after('subject');               // نوع السند التنفيذي
            $table->string('defendant')->nullable()->after('sanad');             // المنفَّذ ضده
            $table->unsignedInteger('amount')->default(0)->after('defendant');   // قيمة المطالبة
            $table->text('notes')->nullable()->after('amount');
            $table->json('docs')->nullable()->after('notes');                    // مستندات (محاكاة الرفع)
            $table->string('client_code')->nullable()->after('user_id');

            // التحليل الذكيّ
            $table->boolean('ai_done')->default(false)->after('docs');
            $table->text('ai_summary')->nullable()->after('ai_done');
            $table->json('ai_missing')->nullable()->after('ai_summary');
            $table->json('ai_procedures')->nullable()->after('ai_missing');

            // القرار والتسعير والعرض
            $table->string('decision')->nullable()->after('ai_procedures');      // مقبول/مرفوض
            $table->unsignedInteger('fee')->default(0)->after('decision');
            $table->unsignedInteger('vat')->default(0)->after('fee');
            $table->string('duration')->nullable()->after('vat');
            $table->string('pay_method')->nullable()->after('duration');
            $table->boolean('fee_approved')->default(false)->after('pay_method');
            $table->string('offer_status')->nullable()->after('fee_approved');   // مقبول/مرفوض/استفسار

            // السداد وفتح الملف
            $table->string('invoice_no')->nullable()->after('offer_status');
            $table->boolean('paid')->default(false)->after('invoice_no');
            $table->timestamp('paid_at')->nullable()->after('paid');
            $table->string('exec_no')->nullable()->after('paid_at');             // رقم التنفيذ ##-2026-تنفيذ
        });

        // ربط فاتورة أتعاب التنفيذ بطلبها (نظير consult_id/case_id على invoices)
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('exec_id')->nullable()->after('case_id')->constrained('executions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exec_id');
        });
        Schema::table('executions', function (Blueprint $table) {
            $table->dropColumn([
                'stage', 'sanad', 'defendant', 'amount', 'notes', 'docs', 'client_code',
                'ai_done', 'ai_summary', 'ai_missing', 'ai_procedures',
                'decision', 'fee', 'vat', 'duration', 'pay_method', 'fee_approved', 'offer_status',
                'invoice_no', 'paid', 'paid_at', 'exec_no',
            ]);
        });
    }
};
