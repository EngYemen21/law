<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            // ختم تذكير سداد فاتورة أتعاب التنفيذ (idempotent — لا يُذكَّر مرتين)
            $table->dateTime('payment_reminder_sent_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropColumn('payment_reminder_sent_at');
        });
    }
};
