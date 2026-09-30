<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// **ملخّص الاستشارة منسّقاً** (طلب المالك 2026-09-30) — نظير `ticket_summaries.*_html`: المنسّق (HTML منقّى) أصلٌ
// يُحرَّر ويصل الموكّلَ بتنسيقه، و`summary` النصّ العاديّ مشتقٌّ منه (`HasRichText`). null = ملخّصٌ نصّيّ قديم.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->text('summary_html')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dropColumn('summary_html');
        });
    }
};
