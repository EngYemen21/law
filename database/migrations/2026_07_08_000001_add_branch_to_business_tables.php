<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إضافة عمود «الفرع» الصريح لجداول العمل (تذاكر/قضايا/تنفيذ) لعزل رؤية الموظف بحسب فرعه.
 * الاستشارات والمواعيد تحملان branch أصلاً. يُختم الفرع آلياً عند الإنشاء عبر فرع المحامي المختار.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['tickets', 'cases', 'executions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('branch')->nullable()->index()->after('assigned_lawyer_id');
            });
        }

        // backfill: فرع السجل = فرع المحامي المسند (عبر assigned_lawyer_id)، ثم الفرع الرئيسي لما بقي
        $lawyerBranch = DB::table('users')->whereNotNull('branch')->pluck('branch', 'id'); // id => branch
        foreach (['tickets', 'cases', 'executions'] as $table) {
            foreach ($lawyerBranch as $id => $branch) {
                DB::table($table)->where('assigned_lawyer_id', $id)->whereNull('branch')->update(['branch' => $branch]);
            }
        }

        $default = DB::table('branches')->orderBy('id')->value('name');
        if ($default) {
            foreach (['tickets', 'cases', 'executions'] as $table) {
                DB::table($table)->whereNull('branch')->update(['branch' => $default]);
            }
        }
    }

    public function down(): void
    {
        foreach (['tickets', 'cases', 'executions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('branch');
            });
        }
    }
};
