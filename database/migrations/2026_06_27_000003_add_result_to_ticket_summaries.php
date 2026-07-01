<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_summaries', function (Blueprint $table) {
            // نتيجة الجلسة (محضر/توصيات) ومسار اعتمادها: none → pending_lawyer → pending_admin → approved
            $table->text('result')->nullable()->after('key_points');
            $table->string('result_status', 20)->default('none')->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_summaries', function (Blueprint $table) {
            $table->dropColumn(['result', 'result_status']);
        });
    }
};
