<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // حالة الحساب (تفعيل/إيقاف) — الموقوف يُمنع من الدخول
            $table->string('status', 20)->default('active')->after('role'); // active | suspended
            // بيانات العمل (للموظفين والمحامين)
            $table->string('branch')->nullable()->after('phone');
            $table->string('department')->nullable()->after('branch');
            $table->string('job_title')->nullable()->after('department'); // الصفة: موظف خدمة عملاء / محامٍ / …
            // الأجر
            $table->string('pay_type', 12)->nullable()->after('job_title'); // salary | pct | both | session
            $table->unsignedInteger('salary')->default(0)->after('pay_type');
            $table->decimal('pay_pct', 5, 2)->nullable()->after('salary');
            $table->unsignedInteger('session_fee')->nullable()->after('pay_pct');
            // بيانات إضافية
            $table->string('national_id', 20)->nullable()->after('session_fee');
            $table->date('join_date')->nullable()->after('national_id');
            $table->string('work_start', 8)->nullable()->after('join_date');
            $table->string('work_end', 8)->nullable()->after('work_start');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'status', 'branch', 'department', 'job_title',
                'pay_type', 'salary', 'pay_pct', 'session_fee',
                'national_id', 'join_date', 'work_start', 'work_end',
            ]);
        });
    }
};
