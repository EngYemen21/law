<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نصيب المحامي من أتعاب التنفيذ — نظير `cases.lawyer_pct/lawyer_fee` (قرار المالك 2026-09-28).
 * تحدّده الإدارة عند اعتماد الأتعاب؛ و`lawyer_fee` فارغٌ في النموذج النسبيّ (لا مبلغ مقدَّم يُعرف).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->unsignedTinyInteger('lawyer_pct')->nullable()->after('collection_fee_pct');
            $table->unsignedInteger('lawyer_fee')->nullable()->after('lawyer_pct');
        });
    }

    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropColumn(['lawyer_pct', 'lawyer_fee']);
        });
    }
};
