<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * علم جلب ملخّص AI Companion من Zoom: عند ضبطه يُعرف أنّ الملخّص جُلب من Zoom
 * ومُنع تكرار الاستطلاع (zoom:pull-summaries). الملخّص نفسه يُخزَّن في حقل summary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dateTime('zoom_summary_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dropColumn('zoom_summary_at');
        });
    }
};
