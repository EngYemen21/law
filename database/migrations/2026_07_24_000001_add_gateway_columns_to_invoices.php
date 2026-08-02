<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ربط الفاتورة بدفعة بوّابة الدفع (Moyasar): معرّف فاتورة البوّابة + معرّف الدفعة — للمطابقة والتدقيق.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('gateway_ref')->nullable()->after('paid');        // معرّف فاتورة البوّابة
            $table->string('gateway_payment_id')->nullable()->after('gateway_ref'); // معرّف الدفعة المؤكَّدة
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['gateway_ref', 'gateway_payment_id']);
        });
    }
};
