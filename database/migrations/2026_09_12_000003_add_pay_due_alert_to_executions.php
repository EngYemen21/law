<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ختمُ تنبيه انقضاء مهلة الوفاء — يمنع تكرار البريد كلّ نصف ساعة على الملفّ نفسه،
 * كما يفعل `payment_reminder_sent_at` مع تذكير السداد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->timestamp('pay_due_alert_sent_at')->nullable()->after('pay_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropColumn('pay_due_alert_sent_at');
        });
    }
};
