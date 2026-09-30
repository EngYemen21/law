<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// **ملخّص التذكرة منسّقاً** (طلب المالك 2026-09-30): النسخة المنسّقة (HTML منقّى — `RichHtml`) أصلٌ يُحرَّر ويصل
// العميلَ بتنسيقه، والأعمدة النصّيّة القائمة تبقى نصّاً عاديّاً مشتقّاً منها لسياق الذكاء والمعاينات والتحقّق.
// null = لا نسخة منسّقة (ملخّصٌ قديم أو مسودّة آلة) — يُعرض نصّه فقراتٍ وقوائم (`SummaryText`).
return new class extends Migration
{
    private const COLUMNS = ['case_summary', 'attachments_summary', 'facts', 'key_points'];

    public function up(): void
    {
        Schema::table('ticket_summaries', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->text($column.'_html')->nullable()->after($column);
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_summaries', function (Blueprint $table) {
            $table->dropColumn(array_map(fn (string $c) => $c.'_html', self::COLUMNS));
        });
    }
};
