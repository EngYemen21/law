<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * عزل دعوات الاجتماعات بحسب المُرسِل (المحامي/الموظف) لا الفرع:
 * إضافة مرجع المُرسِل sent_by_id، مع تعبئة رجعية بمطابقة اسم sent_by النصّي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meet_requests', function (Blueprint $table) {
            $table->foreignId('sent_by_id')->nullable()->after('sent_by')->constrained('users')->nullOnDelete();
        });

        // تعبئة رجعية أفضل-جهد: sent_by = «الاسم (الدور)» → استخرج الاسم وطابقه بمستخدم
        foreach (DB::table('meet_requests')->whereNotNull('sent_by')->get(['id', 'sent_by']) as $row) {
            $name = trim(preg_replace('/\s*\(.*$/u', '', (string) $row->sent_by));
            if ($name === '') {
                continue;
            }
            $uid = DB::table('users')->where('name', $name)->value('id');
            if ($uid) {
                DB::table('meet_requests')->where('id', $row->id)->update(['sent_by_id' => $uid]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('meet_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by_id');
        });
    }
};
