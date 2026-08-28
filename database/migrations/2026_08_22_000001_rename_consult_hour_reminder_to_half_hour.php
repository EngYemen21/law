<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طبقة تذكير الاستشارة القريبة صارت «قبل 30 دقيقة» بدل «قبل ساعة»، وقناتها رسالة نصّية.
 *
 * إعادة تسمية لا عمود جديد: إبقاء reminder_1h_sent_at ميتاً إلى جانب عمود آخر يعني
 * اسمين لمعنى واحد وأحدهما كاذب. الأختام المكتوبة سابقاً تبقى صالحة — كلاهما يعني
 * «أُرسل تذكير الطبقة القريبة» فلا يُعاد إرساله لموعد مضى.
 *
 * جدول case_hearings لا يُمسّ: طبقته ساعة فعلاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->renameColumn('reminder_1h_sent_at', 'reminder_30m_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->renameColumn('reminder_30m_sent_at', 'reminder_1h_sent_at');
        });
    }
};
