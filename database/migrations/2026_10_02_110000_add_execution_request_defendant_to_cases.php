<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **المنفَّذ ضده يُرفع مع طلب فتح التنفيذ** (قرار المالك 2026-10-02) — كان ملفّ التنفيذ يأخذه من «الخصم» في
 * تذكرة القضيّة، والتذكرة لم تكن تطلبه إلّا لقسم التنفيذ، فيُفتح ملفّ الحكم بـ«المنفَّذ ضده —».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->string('execution_request_defendant', 190)->nullable()->after('execution_request_amount');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn('execution_request_defendant');
        });
    }
};
