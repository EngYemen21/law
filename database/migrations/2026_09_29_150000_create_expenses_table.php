<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **مصروفات المكتب** (المرحلة ب من §١٢ في `docs/finance-plan.md`، طلب المالك 2026-09-29).
 *
 * المبلغ **بالهللة** (خلاف توصية ق٦ بالريال): فواتير الموردين بكسور، والمقبوضات في `payments`
 * بالهللة أصلاً — فالأرباح والخسائر تجمع وحدةً واحدة. و`amount_halalas` الإجماليّ المدفوع شاملاً
 * ضريبة المشتريات، و`vat_halalas` جزؤها (الأرباح والخسائر بلا ضريبة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_no', 20)->nullable()->unique(); // سند الصرف — يُمنح عند الاعتماد
            $table->date('spent_on');
            $table->string('category', 32);
            $table->string('description', 300);
            $table->unsignedBigInteger('amount_halalas');
            $table->unsignedBigInteger('vat_halalas')->default(0);
            $table->string('vendor', 150)->nullable();
            $table->string('paid_from', 16); // cash | bank
            $table->string('reference', 100)->nullable(); // مرجع التحويل أو الشيك
            $table->string('document_path')->nullable(); // فاتورة المورّد أو الإيصال
            $table->string('status', 16)->index();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('reject_reason', 300)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->timestamps();
            $table->index(['status', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
