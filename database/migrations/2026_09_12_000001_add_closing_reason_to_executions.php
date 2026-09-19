<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سبب إنهاء ملفّ التنفيذ — الواقع ينهيه بسداد كامل أو تسوية أو إعسار أو تنازل، وكان الإغلاق
 * بلا سبب فلا يُعرف لاحقاً كيف انتهى الحقّ (ولا يُقرأ في تقارير المكتب).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->string('closed_reason')->nullable()->after('exec_no');
        });
    }

    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropColumn('closed_reason');
        });
    }
};
