<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وقائع طلب الاستشارة حقلاً مستقلاً (قرار المالك 2026-09-29).
 *
 * كانت صفحة «حجز استشارة» تدمج الوقائع في الموضوع وتقصّ الناتج عند 120 حرفاً، فلا يصل المسعّرَ
 * والمحاميَ من سؤال العميل إلّا مطلعه. الموضوع يبقى عنواناً، والوقائع هنا كاملة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->text('details')->nullable()->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
