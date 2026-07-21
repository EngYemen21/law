<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دورة الاستشارة المرئية المتكاملة مع Zoom: إطلاق الرابط الموقوت، كلمة مرور الاجتماع للـSDK،
 * وإحصاءات الجلسة (دخول/خروج/مدة) والتفريغ/التسجيل — كلّها على سجلّ الاستشارة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dateTime('link_released_at')->nullable();   // وقت إطلاق الرابط (قبل 5د) — الزر معطّل قبله
            $table->string('meet_password')->nullable();        // كلمة مرور اجتماع Zoom (للـWeb SDK)
            $table->dateTime('join_time')->nullable();          // من تقرير Zoom
            $table->dateTime('leave_time')->nullable();
            $table->unsignedInteger('duration_sec')->nullable(); // مدة الجلسة بالثواني
            $table->longText('transcript')->nullable();          // النصّ التفريغي (VTT منظّف)
            $table->string('recording_url')->nullable();         // رابط التسجيل السحابي
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dropColumn([
                'link_released_at', 'meet_password', 'join_time', 'leave_time',
                'duration_sec', 'transcript', 'recording_url',
            ]);
        });
    }
};
