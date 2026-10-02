<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **نشرُ دراسة التنفيذ للعميل غيرُ اعتمادها** (قرار المالك 2026-10-02): الدراسة تُعتمد داخليّاً متى اعتُمدت، ولا
 * تصل العميل إلّا والملفّ في مرحلة الدراسة فما دون (`Execution::studyPublishable`). كان الاعتماد نفسه يكشفها —
 * فملفٌّ «قيد التنفيذ» يصل عميلَه «نواقص مطلوبة». وما اعتُمد قبل اليوم كان قد نُشر فعلاً، فيُختم بتاريخ اعتماده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $t) {
            $t->timestamp('ai_released_at')->nullable()->after('ai_approved_by');
        });

        DB::table('executions')->whereNotNull('ai_approved_at')->update(['ai_released_at' => DB::raw('ai_approved_at')]);
    }

    public function down(): void
    {
        Schema::table('executions', function (Blueprint $t) {
            $t->dropColumn('ai_released_at');
        });
    }
};
