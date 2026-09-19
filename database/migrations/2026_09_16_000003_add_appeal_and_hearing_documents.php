<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->string('appeal_status', 32)->nullable()->after('ruling');
            $table->date('appeal_deadline_at')->nullable()->after('appeal_status');
            $table->string('appeal_request_no', 64)->nullable()->after('appeal_deadline_at');
            $table->string('appeal_court', 160)->nullable()->after('appeal_request_no');
            $table->string('appeal_circuit', 160)->nullable()->after('appeal_court');
            $table->text('appeal_ruling')->nullable()->after('appeal_circuit');
            $table->date('appeal_filed_at')->nullable()->after('appeal_ruling');
            $table->date('appeal_judged_at')->nullable()->after('appeal_filed_at');
        });

        Schema::table('case_documents', function (Blueprint $table) {
            $table->foreignId('hearing_id')->nullable()->after('case_id')->constrained('case_hearings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('case_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hearing_id');
        });

        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn([
                'appeal_status',
                'appeal_deadline_at',
                'appeal_request_no',
                'appeal_court',
                'appeal_circuit',
                'appeal_ruling',
                'appeal_filed_at',
                'appeal_judged_at',
            ]);
        });
    }
};
