<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **إعادة الجدولة دورةٌ لها ذاكرة.** (قرار المالك 2026-09-25)
 *
 * كانت إعادة الجدولة تمحو ولا تحفظ:
 *
 * - **الموعد الملغى يفقد صلته بالاستشارة** لحظةَ حجز الموعد التالي، لأنّ الربط الوحيد
 *   `consults.appointment_id` يُستبدل. فلا يبقى أثرٌ أنّ للاستشارة موعداً سابقاً ولا لماذا أُلغي.
 * - **لا عدّاد:** فلا سبيل لسقف «مرّتان ثمّ الإدارة».
 * - **حالة الفرز تضيع:** استشارةٌ «محالة للمحامي» تعود بعد الموعد الجديد «جديدة».
 * - **طلب العميل بلا حالة:** يُرسَل مرّاتٍ بلا حدّ، ولا يرى الطاقم أنّ طلباً معلّق.
 * - **الجلسة القضائيّة المؤجَّلة تُكتب فوقها:** فيُمحى أنّها أُجّلت ومتى.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // صلةٌ ثابتة بالاستشارة — لا تُستبدل كما يُستبدل `consults.appointment_id`
            $table->foreignId('consult_id')->nullable()->after('ticket_id')->constrained('consults')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('status');
            $table->string('cancel_reason', 600)->nullable()->after('cancelled_at');
        });

        Schema::table('consults', function (Blueprint $table) {
            $table->unsignedTinyInteger('reschedule_count')->default(0)->after('appointment_id');
            // الحالة التي تعود إليها الاستشارة حين يُعتمد موعدها الجديد
            $table->string('resume_status', 60)->nullable()->after('reschedule_count');
            $table->timestamp('reschedule_requested_at')->nullable()->after('resume_status');
            $table->string('reschedule_request_note', 500)->nullable()->after('reschedule_requested_at');
        });

        Schema::table('case_hearings', function (Blueprint $table) {
            // الجلسة التي أُجِّلت فوُلدت هذه منها — سلسلةُ التأجيلات تُقرأ منها
            $table->foreignId('postponed_from_id')->nullable()->after('case_id')->constrained('case_hearings')->nullOnDelete();
        });

        // الصفوف القائمة: الموعد المرتبط اليوم يُنسب لاستشارته. والملغاة منها قبل اليوم لا تُستعاد
        // صلتُها — الربط الوحيد الذي كان يدلّ عليها استُبدل، ولا تخمين.
        DB::table('consults')->whereNotNull('appointment_id')->orderBy('id')->each(function ($c) {
            DB::table('appointments')->where('id', $c->appointment_id)->whereNull('consult_id')->update(['consult_id' => $c->id]);
        });
    }

    public function down(): void
    {
        Schema::table('case_hearings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('postponed_from_id');
        });

        Schema::table('consults', function (Blueprint $table) {
            $table->dropColumn(['reschedule_count', 'resume_status', 'reschedule_requested_at', 'reschedule_request_note']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consult_id');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
