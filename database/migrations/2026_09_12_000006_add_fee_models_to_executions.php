<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نماذج الأتعاب في التنفيذ (قرار المالك 2026-09-12): المكتب يقرّر النموذج — مبلغٌ ثابت أو
 * نسبةٌ من كلّ محصَّل — والعميل يقرّر خطّة السداد في النموذج الثابت وحده: كاملاً أو ثلاث دفعات.
 *
 * وكان `pay_method` نصّاً بأربعة خيارات لا يقرؤها كود: العميل يختار «دفعات» فتصدر فاتورةٌ
 * واحدة كاملة. فالنموذج والخطّة عمودان يقرؤهما المحرّك، و`pay_method` يبقى عنواناً مشتقّاً.
 *
 * و`collection_fee_pct` بهذا الاسم قصداً: في بطاقة التسعير نسبةٌ أخرى (نسبة من قيمة
 * المطالبة) تُحسب في المتصفّح لتُنتج مبلغاً ثابتاً — وهي مدخل تسعير لا نموذج سداد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->string('fee_mode', 10)->nullable()->after('pay_method');            // fixed | percent — null: صفٌّ سابق ⇒ fixed
            $table->decimal('collection_fee_pct', 5, 2)->nullable()->after('fee_mode');  // 0.01–100.00 — للنموذج النسبيّ وحده
            $table->string('pay_plan', 10)->nullable()->after('collection_fee_pct');     // full | install — قرار العميل عند القبول
            $table->unsignedTinyInteger('installments_total')->default(0)->after('pay_plan');
            $table->unsignedTinyInteger('installments_paid')->default(0)->after('installments_total');
        });
    }

    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropColumn(['fee_mode', 'collection_fee_pct', 'pay_plan', 'installments_total', 'installments_paid']);
        });
    }
};
