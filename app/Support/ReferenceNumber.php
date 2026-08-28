<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * مولّد الأرقام المرجعية — مصدر واحد بحلقة إعادة محاولة لكل كيانات المنظومة.
 *
 * لماذا: ستّة مواضع كانت تولّد `PREFIX-YYYY-` + random_int(1,9999) مباشرةً على أعمدة
 * مُعرَّفة `unique()` (القضايا · التنفيذ مرّتين · المخاطبات · الاستشارات · طلبات الاجتماع)
 * بلا أي إعادة محاولة. المساحة 9999 رقماً في السنة، وحدّ عيد الميلاد يجعل احتمال تصادم
 * واحد على الأقل ~50% بعد ~118 سجلاً و~99% بعد ~350.
 *
 * وعند التصادم يُرمى QueryException **داخل المعاملة** بلا التقاط في أي مستوى، فيرتدّ كل
 * شيء بخطأ 500 خام. ولأنه ليس 422 لا تلتقطه معالجات الأخطاء في الواجهة، فلا تظهر رسالة
 * عربية — يرى المستخدم فشلاً صامتاً، وإعادة المحاولة تنجح برقم عشوائيّ جديد فيبدو العطل
 * **متقطّعاً**: «أحياناً يعمل وأحياناً لا».
 *
 * النمط الصحيح كان موجوداً في المشروع منذ البداية — حلقة do…while في توليد رقم التذكرة
 * (TicketController) — لكنه طُبّق على التذاكر وحدها.
 */
class ReferenceNumber
{
    /** أقصى عدد محاولات قبل اللجوء إلى مدى موسّع (حماية من حلقة لا تنتهي). */
    private const MAX_TRIES = 25;

    /**
     * يعيد رقماً مرجعيّاً غير مستعمل لهذه السنة.
     *
     * @param  class-string<Model>  $model  النموذج الذي يُفحص فيه التفرّد
     * @param  string  $column  عمود الرقم (number / ref)
     * @param  string  $prefix  السابقة بلا شرطة (CASE / EXE / MKH / CN / MR / INV)
     */
    public static function next(string $model, string $column, string $prefix): string
    {
        $year = now()->format('Y');
        $taken = fn (string $candidate): bool => $model::where($column, $candidate)->exists();

        for ($i = 0; $i < self::MAX_TRIES; $i++) {
            $number = self::compose($prefix, $year, random_int(1, 9999), 4);

            if (! $taken($number)) {
                return $number;
            }
        }

        // المساحة السنوية امتلأت فعليّاً — تُوسَّع بدل أن يفشل الطلب (الرقم يبقى مقروءاً).
        do {
            $number = self::compose($prefix, $year, random_int(10000, 999999), 6);
        } while ($taken($number));

        return $number;
    }

    private static function compose(string $prefix, string $year, int $serial, int $pad): string
    {
        return $prefix.'-'.$year.'-'.str_pad((string) $serial, $pad, '0', STR_PAD_LEFT);
    }
}
