<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->unsignedInteger('lawyer_fee')->nullable()->after('fee'); // قيمة أتعاب المحامي (ر.س)
            $table->unsignedTinyInteger('lawyer_pct')->nullable()->after('lawyer_fee'); // نسبة أتعاب المحامي %
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn(['lawyer_fee', 'lawyer_pct']);
        });
    }
};
