<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **ساعات دوام الموظّف الفرديّة** — قرار المالك 2026-10-01: حُذفت.
 *
 * كانت تُحفظ نصّاً وتُعرض في تبويب الموظفين وحده، ولا يقرؤها الحجز ولا الحضور: الحجز بدوام
 * المكتب من «إعدادات النظام» (`LawyerAvailability::workHours`). والرجوع يعيد العمودين فارغين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['work_start', 'work_end']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('work_start', 8)->nullable()->after('join_date');
            $table->string('work_end', 8)->nullable()->after('work_start');
        });
    }
};
