<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('closure_reason_code', 60)->nullable()->after('status');
            $table->text('closure_notes')->nullable()->after('closure_reason_code');
            $table->foreignId('closed_by_id')->nullable()->after('closure_notes')->constrained('users')->nullOnDelete();
            $table->boolean('is_frozen')->default(false)->after('closed_by_id');
            $table->timestamp('outcome_decision_at')->nullable()->after('is_frozen');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by_id');
            $table->dropColumn([
                'closure_reason_code',
                'closure_notes',
                'is_frozen',
                'outcome_decision_at',
            ]);
        });
    }
};
