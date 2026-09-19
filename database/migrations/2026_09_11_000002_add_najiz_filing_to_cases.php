<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **بيانات الرفع والقيد في ناجز** (قرار المالك 2026-09-11 — الخطّة ب).
 *
 * كان زرّ «الاعتماد النهائيّ ورفع الدعوى» يجعل القضيّة «منظورة» ويبلّغ العميل برفعها قبل أن
 * تُرفع في ناجز أصلاً، ولا مكان يُسجَّل فيه رقم الطلب ولا رقم القضيّة ولا الدائرة. صار المسار:
 * اعتماد النصّ ⇐ تسجيل الرفع (رقم الطلب) «بانتظار القيد» ⇐ تسجيل القيد (رقم القضيّة) «منظورة».
 * أعمدةٌ مضافة كلّها nullable — لا أثر على صفوفٍ قائمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->string('najiz_request_no', 60)->nullable()->after('pleading_status');
            $table->date('filed_at')->nullable()->after('najiz_request_no');
            $table->string('najiz_case_no', 60)->nullable()->after('filed_at');
            $table->string('court', 160)->nullable()->after('najiz_case_no');
            $table->string('circuit', 160)->nullable()->after('court');
            $table->date('registered_at')->nullable()->after('circuit');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn(['najiz_request_no', 'filed_at', 'najiz_case_no', 'court', 'circuit', 'registered_at']);
        });
    }
};
