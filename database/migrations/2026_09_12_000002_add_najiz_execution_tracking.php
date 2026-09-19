<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **مسار التنفيذ في ناجز داخل المرحلتين 7 و8** (قرار المالك 2026-09-12) — نظير أعمدة القيد في
 * القضايا (`CaseFiling`): ما يرجع من المنصّة يُحفظ ويُعرض بدل أن يبقى نصّاً حرّاً في الإجراءات.
 *
 * الترتيب الواقعيّ: رفع الطلب (رقم الطلب) ← قيده لدى محكمة التنفيذ (المحكمة والدائرة) ← أمر
 * التنفيذ وإبلاغ المنفَّذ ضدّه (تبدأ منه مهلة الوفاء) ← إجراءات عدم الوفاء ← التحصيل.
 *
 * `court` موجود أصلاً في الجدول فيُستعمل لمحكمة التنفيذ — لا عمود ثانٍ لنفس المعنى.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->string('najiz_request_no')->nullable()->after('exec_no');   // رقم الطلب في ناجز
            // **مفهرسٌ لا فريد.** هو المرجع الخارجيّ الذي يُبحث به عن الملفّ (من ناجز إلى عندنا)،
            // فبلا فهرس يمسح الجدول كلّه. ولا تفرّد: تكرار الرقم قبل القيد تصحيحٌ للبيانات لا
            // رفعٌ ثانٍ (كما في القضايا)، وقيدُ تفرّدٍ يرفض التصحيح ويكسر نقل الصفوف القديمة.
            $table->index('najiz_request_no');
            $table->date('najiz_filed_at')->nullable()->after('najiz_request_no');
            $table->string('circuit')->nullable()->after('court');              // دائرة التنفيذ
            $table->date('registered_at')->nullable()->after('najiz_filed_at'); // تاريخ القيد
            $table->date('notified_at')->nullable()->after('registered_at');    // إبلاغ المنفَّذ ضدّه (أمر التنفيذ)
            $table->date('pay_due_at')->nullable()->after('notified_at');       // نهاية مهلة الوفاء — تُحسب من الإبلاغ
            $table->json('measures')->nullable()->after('pay_due_at');          // إجراءات عدم الوفاء المطبَّقة
            $table->unsignedBigInteger('collected')->default(0)->after('measures'); // المحصَّل من المطالبة
        });
    }

    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropColumn(['najiz_request_no', 'najiz_filed_at', 'circuit', 'registered_at', 'notified_at', 'pay_due_at', 'measures', 'collected']);
        });
    }
};
