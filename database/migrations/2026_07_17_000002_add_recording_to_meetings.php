<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مخرجات الجلسة من Zoom للاجتماعات (محاذاة الاستشارات): رابط التسجيل السحابي، مسار النصّ
 * التفريغي المحفوظ محلياً، وإحصاءات الجلسة الفعلية (دخول/خروج/مدّة) من أحداث المشاركين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('recording_url')->nullable();     // رابط التسجيل السحابي من Zoom
            $table->string('transcript_path')->nullable();   // مسار ملف النصّ المحفوظ محلياً
            $table->dateTime('join_time')->nullable();
            $table->dateTime('leave_time')->nullable();
            $table->unsignedInteger('duration_sec')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['recording_url', 'transcript_path', 'join_time', 'leave_time', 'duration_sec']);
        });
    }
};
