<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// لأيّ بوّابةٍ ينتمي `gateway_ref` — كان المرجع بلا اسم بوّابة فيستحيل تشغيل بوّابتين معاً (مراجعة بوّابات الدفع
// 2026-09-30). كلّ مرجعٍ قائم أنشأه ميسّر، البوّابة الوحيدة حتى الآن.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('gateway', 32)->nullable()->after('paid');
        });

        DB::table('invoices')->whereNotNull('gateway_ref')->where('gateway_ref', '!=', '')->update(['gateway' => 'moyasar']);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('gateway');
        });
    }
};
