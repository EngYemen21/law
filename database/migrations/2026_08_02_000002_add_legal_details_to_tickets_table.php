<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('opponent_name')->nullable()->after('type');
            $table->string('opponent_id')->nullable()->after('opponent_name');
            $table->unsignedBigInteger('claim_amount')->nullable()->after('opponent_id');
            $table->string('court_name')->nullable()->after('claim_amount');
            $table->string('priority', 20)->default('متوسطة')->after('court_name');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['opponent_name', 'opponent_id', 'claim_amount', 'court_name', 'priority']);
        });
    }
};
