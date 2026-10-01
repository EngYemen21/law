<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// مالٌ خُصم ولم يُطبَّق (مبلغ غير مطابق، دفعة ثانية، فاتورة لا تقبل السداد) — يُختم مرّةً فيُنبَّه له مرّة
// ويُستعلم عنه (تدقيق الدفع 2026-09-30).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('refund_required_at')->nullable()->after('reconciled_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('refund_required_at');
        });
    }
};
