<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهرس على consults.paid_at — شاشتا الإيرادات والتقارير تصفّيان به في كل تحميل
 * (whereNotNull('paid_at') + groupBy channel) وكان مسحاً كاملاً للجدول.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dropIndex('consults_paid_at_index');
        });
    }
};
