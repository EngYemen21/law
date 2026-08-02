<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// فاتورة الاستشارة: ربط اختياري بالاستشارة (مثل case_id للقضايا) — تُصدر عند تسعير الإدارة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('consult_id')->nullable()->after('case_id')
                ->constrained('consults')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consult_id');
        });
    }
};
