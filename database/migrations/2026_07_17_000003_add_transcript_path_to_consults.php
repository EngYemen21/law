<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مسار ملف النصّ التفريغي المحفوظ محلياً للاستشارة (بقية أعمدة التسجيل موجودة من 07-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->string('transcript_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dropColumn('transcript_path');
        });
    }
};
