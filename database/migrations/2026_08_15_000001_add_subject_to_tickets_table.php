<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// موضوع التذكرة (عنوان مختصر يكتبه العميل) — يطابق حقل «موضوع التذكرة» في نموذج التصميم المرجعي.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('subject')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('subject');
        });
    }
};
