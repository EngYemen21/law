<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **المبلغ المحكوم به يُرفع مع طلب فتح التنفيذ** (قرار المالك 2026-09-30) — كان ملفّ التنفيذ يأخذ مبلغه من
 * التذكرة وحدها، فيُفتح بصفرٍ حين لم يُدخل العميل مبلغاً (والحكم نصٌّ حرّ)، فيستحيل تسجيل أيّ تحصيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->unsignedBigInteger('execution_request_amount')->nullable()->after('execution_request_reason');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn('execution_request_amount');
        });
    }
};
