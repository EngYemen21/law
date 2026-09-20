<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **عمودٌ واحدُ الوحدة لدفتر المدفوعات: `amount_halalas`.**
 *
 * العمود `payments.amount` يخلط وحدتين في العمود نفسه: صفّ البوّابة يُكتب بالهللة كما تردّها
 * ميسّر (`PaymentReconciler::record`)، وصفّ التحصيل اليدويّ يُكتب بالريال نقلاً عن
 * `invoices.amount` (`PaymentReconciler::settleManual`). فصفٌّ بـ`51800` وصفٌّ بـ`518` يعنيان
 * المبلغ نفسه، والعمود الذي كُتب ليكون مرجعَ المطابقة المحاسبيّة **لا يمكن جمعه**.
 *
 * **وتصحيحُ تعليقٍ خاطئ:** المهاجرة الأصليّة `2026_07_24_000002_create_payments_table` تقول
 * عن `amount` إنّه «بالهللة (كما في `invoices`)» — و`invoices.amount` **بالريال**. فالتعليق
 * وصفَ نيّةً لم تقع، وهو ما أخفى الخلط.
 *
 * **ولماذا عمودٌ جديد لا تصحيحُ القائم؟** قيم `payments.amount` مرجعٌ حرفيّ في اختبارات قائمة
 * (`PaymentLedgerTest` يؤكّد `51800` كما وردت من البوّابة)، وهي أيضاً لقطة «ما قالته البوّابة»
 * الصالحة للتدقيق. فالقديم يبقى كما هو حرفاً، والجديد **بالهللة دائماً** بلا استثناء.
 *
 * **ولا قارئ ينتقل إلى العمود الجديد في هذا الحفظ.** النقل حفظٌ لاحق: هذه المهاجرة وكتابةُ
 * العمودين معاً في `PaymentReconciler` هما كلّ ما في م٠.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // bigInteger: الهللة تضرب الريال في ١٠٠، و`integer` يضيق عن مبالغ القضايا الكبيرة
            // nullable: صفوفٌ قد تُكتب من مسارٍ قديم لم يُحدَّث بعد — null تعني «غير معروفة» لا صفراً
            $table->bigInteger('amount_halalas')->nullable()->after('amount');
        });

        // التعبئة الرجعيّة بمعيارٍ مقروءٍ من الكود لا بالتخمين: `gateway` هو ما يفرّق المسارين —
        // `settleManual` وحده يكتب `'manual'`، وكلّ ما عداه (`record` و backfill المهاجرة الأصليّة)
        // يكتب `'moyasar'` بمبلغ البوّابة بالهللة.
        DB::table('payments')->where('gateway', 'manual')->update(['amount_halalas' => DB::raw('amount * 100')]);
        DB::table('payments')->where('gateway', '!=', 'manual')->update(['amount_halalas' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('amount_halalas');
        });
    }
};
