<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **طلب فتح تنفيذ الحكم يُرفع للإدارة العليا** (قرار المالك 2026-09-29) — كان المحامي يفتح ملفّ التنفيذ
 * مباشرةً. الطلب القائم على القضيّة نفسها (من رفعه ومتى ولماذا)، والقرار سطرٌ في سجلّ الانتقالات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->timestamp('execution_requested_at')->nullable();
            $table->foreignId('execution_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('execution_request_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('execution_requested_by');
            $table->dropColumn(['execution_requested_at', 'execution_request_reason']);
        });
    }
};
