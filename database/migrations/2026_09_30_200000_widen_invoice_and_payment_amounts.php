<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **مبالغ الفاتورة والدفعة تتّسع لما يصبّ فيها** (تدقيق P5، 2026-09-30).
 *
 * `invoices.amount` كان INT موقَّعاً (٢٫١ مليار) — وفاتورة «نسبة من المحصّل» تُحسب من تحصيلٍ حدّه سعةُ
 * `executions.amount` (٤٫٢٩ مليار) مع الضريبة؛ فجُرِّب ٢٫٣ مليار فردّه MySQL (ERROR 1264). و`payments.amount`
 * يخزّن **هللاتٍ** لصفوف البوّابة، فحدّه الفعليّ نحو ٢١ مليون ريال فقط. توسيعٌ بلا فقد: القيم القائمة كما هي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->bigInteger('amount')->change();
            $table->unsignedBigInteger('subtotal')->nullable()->change();
            $table->unsignedBigInteger('vat_amount')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->bigInteger('amount')->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->integer('amount')->change();
            $table->unsignedInteger('subtotal')->nullable()->change();
            $table->unsignedInteger('vat_amount')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->integer('amount')->change();
        });
    }
};
