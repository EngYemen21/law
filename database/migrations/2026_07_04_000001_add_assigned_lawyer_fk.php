<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ربط المحامي المسند بحساب حقيقي (FK) بدل الاكتفاء بالاسم النصي، مع إبقاء العمود النصي للعرض
return new class extends Migration
{
    private const TABLES = ['tickets', 'cases', 'executions'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('assigned_lawyer_id')->nullable()
                    ->constrained('users')->nullOnDelete();
            });
        }

        // تعبئة رجعية: مطابقة الاسم النصي مع حسابات المحامين القائمة
        $lawyers = DB::table('users')->where('role', 'lawyer')->get(['id', 'name']);
        foreach (self::TABLES as $name) {
            foreach ($lawyers as $lawyer) {
                DB::table($name)
                    ->whereNull('assigned_lawyer_id')
                    ->where('assigned_lawyer', $lawyer->name)
                    ->update(['assigned_lawyer_id' => $lawyer->id]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('assigned_lawyer_id');
            });
        }
    }
};
