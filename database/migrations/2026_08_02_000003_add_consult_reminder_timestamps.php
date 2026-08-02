<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            // ختم إرسال كل طبقة تذكير (idempotent — لا يُذكَّر مرتين لنفس الطبقة)
            $table->dateTime('reminder_24h_sent_at')->nullable()->after('link_released_at');
            $table->dateTime('reminder_1h_sent_at')->nullable()->after('reminder_24h_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dropColumn(['reminder_24h_sent_at', 'reminder_1h_sent_at']);
        });
    }
};
