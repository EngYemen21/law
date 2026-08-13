<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حقول احترافية لدعوة الاجتماع: المحامي/المختص المسؤول (يُسنَد للاجتماع عند التأكيد) والمدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meet_requests', function (Blueprint $table) {
            $table->foreignId('assigned_lawyer_id')->nullable()->after('sent_by_id')->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('duration_min')->nullable()->after('assigned_lawyer_id');
        });
    }

    public function down(): void
    {
        Schema::table('meet_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_lawyer_id');
            $table->dropColumn('duration_min');
        });
    }
};
