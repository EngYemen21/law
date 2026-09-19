<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // مقترح الذكاء الاصطناعي للمسار والسبب
            $table->string('ai_suggested_track', 40)->nullable()->after('outcome_decision_at');
            $table->text('ai_suggested_reason')->nullable()->after('ai_suggested_track');

            // مقترح المحامي أو الموظف المرفوع للإدارة
            $table->string('proposed_track', 40)->nullable()->after('ai_suggested_reason');
            $table->text('proposed_track_reason')->nullable()->after('proposed_track');
            $table->foreignId('proposed_by_id')->nullable()->after('proposed_track_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('proposed_at')->nullable()->after('proposed_by_id');

            // القرار المعتمد نهائياً من الإدارة العليا
            $table->string('approved_track', 40)->nullable()->after('proposed_at');
            $table->text('approved_track_reason')->nullable()->after('approved_track');
            $table->foreignId('approved_by_id')->nullable()->after('approved_track_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_track_at')->nullable()->after('approved_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proposed_by_id');
            $table->dropConstrainedForeignId('approved_by_id');
            $table->dropColumn([
                'ai_suggested_track',
                'ai_suggested_reason',
                'proposed_track',
                'proposed_track_reason',
                'proposed_at',
                'approved_track',
                'approved_track_reason',
                'approved_track_at',
            ]);
        });
    }
};
