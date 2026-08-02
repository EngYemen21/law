<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * موعد الاجتماع الفعليّ (datetime) لجدولة التذكير + ختم إرسال التذكير (idempotent).
 * (when_label يبقى النصّ المعروض؛ starts_at للمنطق الزمنيّ فقط، وقد يكون null إن تعذّر تحليل الموعد.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable()->after('when_label');
            $table->timestamp('reminder_sent_at')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'reminder_sent_at']);
        });
    }
};
