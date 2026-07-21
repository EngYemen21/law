<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تكافؤ الاجتماعات مع Zoom: كلمة مرور الاجتماع (لتضمين Web SDK)، وملخّص AI Companion
 * من Zoom (يتعايش مع الملخّص البشري المعتمَد) وعلم جلبه لمنع تكرار الاستطلاع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('meet_password')->nullable();   // كلمة مرور اجتماع Zoom (للـWeb SDK)
            $table->longText('zoom_summary')->nullable();   // ملخّص AI Companion من Zoom
            $table->dateTime('zoom_summary_at')->nullable(); // علم الجلب (idempotent)
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['meet_password', 'zoom_summary', 'zoom_summary_at']);
        });
    }
};
