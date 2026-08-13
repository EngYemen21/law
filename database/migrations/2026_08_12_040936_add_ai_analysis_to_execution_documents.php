<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// نتيجة التحليل الذكي لمستند التنفيذ المرفَق (تصنيف + ملخّص) — نظير case_documents.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_documents', function (Blueprint $table) {
            $table->string('doc_type')->nullable()->after('label');
            $table->text('summary')->nullable()->after('doc_type');
        });
    }

    public function down(): void
    {
        Schema::table('execution_documents', function (Blueprint $table) {
            $table->dropColumn(['doc_type', 'summary']);
        });
    }
};
