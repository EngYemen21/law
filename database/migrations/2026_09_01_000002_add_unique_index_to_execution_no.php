<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تفرّد رقم ملفّ التنفيذ.
 *
 * كان يُولَّد بـ`random_int(70, 99)` — ثلاثون قيمة في السنة على عمودٍ بلا قيد، فملفّان
 * لعميلين مختلفين يحملان الرقم نفسه بعد ثلاثين طلباً. صار التوليد عبر
 * `ReferenceNumber::next` بحلقة فحصٍ، والفهرس هنا حارسٌ خلفيّ: التنافس بين طلبين
 * متزامنين قد يُمرّر الفحص، فيمنعه القيد على مستوى قاعدة البيانات.
 *
 * `nullable` فالصفوف غير المدفوعة تحمل `null`، وMySQL لا يعدّ الفراغات تكراراً.
 * والصفوف القائمة بالنمط القديم تبقى كما هي — إلّا أن تتصادم، وعندها يلزم قرار
 * المكتب لا هجرةٌ تُعيد ترقيمها صامتةً.
 */
return new class extends Migration
{
    public function up(): void
    {
        // تصادمٌ قائم يُوقف الهجرة بصوتٍ عالٍ بدل أن يُعالَج صامتاً
        $duplicates = DB::table('executions')
            ->whereNotNull('exec_no')
            ->selectRaw('exec_no, count(*) as n')
            ->groupBy('exec_no')
            ->havingRaw('count(*) > 1')
            ->pluck('exec_no')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'أرقام ملفّات تنفيذ مكرّرة تمنع الفهرس الفريد: '.implode('، ', $duplicates)
                .' — تُعالَج بقرار المكتب (أيّها يحتفظ برقمه) قبل إعادة تشغيل الهجرة.'
            );
        }

        Schema::table('executions', function (Blueprint $table) {
            $table->unique('exec_no');
        });
    }

    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropUnique(['exec_no']);
        });
    }
};
