<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * **تصحيح بياناتٍ لمرّة واحدة: الاستشارات التي أُعيدت جدولتها قبل إصلاح `RescheduleConsult`.**
 *
 * كان الانتقال يمسح `starts_at` ويُبقي `day`/`time`، فتعيد التقاويم وملفّ الاشتراك بناءَ الموعد
 * الملغى منهما. الإصلاح يمنع الصفوف الجديدة؛ وهذا يصحّح القائمة.
 *
 * بصمة الصفّ البائت دقيقة فلا تمسّ غيره: لا `starts_at`، وتاريخ أو وقت باقيان، وموعدٌ مرتبط **ملغى**،
 * والاستشارة نفسها غير ملغاة. المقترح بانتظار الاعتماد موعده «بانتظار الاعتماد» لا «ملغي»،
 * والمؤكَّد يحمل `starts_at` — فلا يطابق أيٌّ منهما.
 *
 * `appointment_id` يبقى: لوحة المواعيد وسجلّ العميل يقرآن تفاصيل الموعد الملغى من خلاله.
 * القيم نصوصٌ حرفيّة (لا تعدادات التطبيق) كي لا يتغيّر معنى المهاجرة إن تغيّرت التعدادات لاحقاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cancelledAppointments = DB::table('appointments')->where('status', 'ملغي')->select('id');

        DB::table('consults')
            ->whereNull('starts_at')
            ->where('status', '!=', 'ملغاة')
            ->whereIn('appointment_id', $cancelledAppointments)
            ->where(fn ($q) => $q->whereNotNull('day')->orWhereNotNull('time'))
            ->update(['day' => null, 'time' => null]);
    }

    /** لا رجوع: التاريخ الممسوح كان تاريخ موعدٍ ملغى لا قيمة لاستعادته. */
    public function down(): void {}
};
