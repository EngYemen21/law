<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// موعد حقيقي (datetime) لجلسة القضية + أختام التذكير — يمكّن جدولة تذكير الجلسات (كالمواعيد/الاستشارات).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_hearings', function (Blueprint $table) {
            $table->dateTime('starts_at')->nullable()->after('time');            // موعد الجلسة الحقيقي (يُحسب من التاريخ+الوقت)
            $table->dateTime('reminder_24h_sent_at')->nullable()->after('outcome'); // ختم تذكير ما قبل 24 ساعة
            $table->dateTime('reminder_1h_sent_at')->nullable()->after('reminder_24h_sent_at'); // ختم تذكير ما قبل ساعة
        });
    }

    public function down(): void
    {
        Schema::table('case_hearings', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'reminder_24h_sent_at', 'reminder_1h_sent_at']);
        });
    }
};
