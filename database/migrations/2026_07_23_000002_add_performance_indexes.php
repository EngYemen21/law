<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس أداء: أعمدة الحالة/الدور تُفلتَر على كل لوحة/قائمة تقريباً وكانت بلا فهرس
 * (مسح جدول كامل). تسرّع لوحات التحكّم والقوائم وإسناد التذاكر والحجز.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index('status');                 // فلترة الحالة على لوحات التحكّم والقوائم
            $table->index(['branch', 'status']);      // لوحة الموظف: فرع + حالة
        });
        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'status']);        // قوائم الأدوار + النشِط (يغطّي role وحده أيضاً)
            $table->index(['role', 'branch']);        // إسناد/عزل بالفرع
        });
        Schema::table('cases', function (Blueprint $table) {
            $table->index('status');
        });
        Schema::table('executions', function (Blueprint $table) {
            $table->index('status');
        });
        Schema::table('consults', function (Blueprint $table) {
            $table->index('status');
            $table->index('session');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['branch', 'status']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'status']);
            $table->dropIndex(['role', 'branch']);
        });
        Schema::table('cases', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
        Schema::table('executions', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
        Schema::table('consults', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['session']);
        });
    }
};
