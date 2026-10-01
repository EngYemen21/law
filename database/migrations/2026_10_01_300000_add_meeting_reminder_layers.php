<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **طبقتا تذكير الاجتماع وإطلاق رابطه** (قرار المالك 2026-10-01) — نظيرُ أعمدة الاستشارة:
 * `reminder_sent_at` يبقى ختمَ البريد الأوّل، و`reminder_near_sent_at` ختمُ تذكير العميل الثاني
 * (إشعار ورسالة نصّيّة)، و`link_released_at` ختمُ إطلاق رابط الغرفة عند فتح الدخول.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $t) {
            $t->timestamp('reminder_near_sent_at')->nullable()->after('reminder_sent_at');
            $t->timestamp('link_released_at')->nullable()->after('reminder_near_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $t) {
            $t->dropColumn(['reminder_near_sent_at', 'link_released_at']);
        });
    }
};
