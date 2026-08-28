<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رفع السقف عن نصّ الرسالة (قرار صاحب المنتج: حقل الرسالة بلا حدّ على ما يكتبه المستخدم).
 *
 * `TEXT` سعته 65535 **بايت**، والعربية بايتان للحرف ⇒ ~32700 حرفاً فقط. والأسوأ أن النصّ
 * يُخزَّن مُهرَّباً HTML (`e()` و`nl2br(e())`) والتهريب يتمدّد: `"` تصير `&quot;` ستّة أضعاف،
 * و`&` تصير `&amp;`، وكل سطر جديد يضيف `<br />`. فنصّ 5000 حرف قد يُخزَّن 30000 حرفاً =
 * 60000 بايت — على حافة السعة تماماً. رفعُ حدّ التحقّق `max:5000` بلا توسيع العمود كان
 * ينقل الانهيار من `last_message` إلى `body` لا أكثر.
 *
 * `MEDIUMTEXT` (16MB ≈ ثمانية ملايين حرف عربي) لا `LONGTEXT`: `post_max_size = 8M`
 * و`max_allowed_packet = 64MB` يسقفان الطلب قبل ذلك بكثير، فسعة الأربعة جيجابايت وهميّة
 * لا تُبلغ أبداً.
 *
 * غير مدمّرة: توسيع نوع لا يفقد بيانات. و`down()` يعيد `TEXT` — ⚠️ وهو **يبتر** أي رسالة
 * تجاوزت 65535 بايتاً بعد هذه المهاجرة (mysql يقصّ صامتاً في الوضع غير الصارم ويرمي في
 * الصارم). الرجوع الآمن = استعادة نسخة احتياطية.
 *
 * sqlite (بيئة الاختبارات) يتجاهل التمييز بين TEXT وMEDIUMTEXT — المهاجرة تمرّ بلا أثر
 * هناك، والحماية الفعلية تخصّ mysql حيث يعمل التطبيق.
 */
return new class extends Migration
{
    /** جداول الرسائل الثلاثة — نفس عمود `body` في كلٍّ منها. */
    private const TABLES = ['ticket_messages', 'case_messages', 'execution_messages'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, fn (Blueprint $t) => $t->mediumText('body')->change());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, fn (Blueprint $t) => $t->text('body')->change());
            }
        }
    }
};
