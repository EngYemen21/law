<?php

namespace App\Support;

use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Meeting;

/**
 * نصّ الحالة المعروض لكل نوع حدث — **مصدر واحد لكل الشاشات**.
 *
 * لماذا: أبلغ مستخدم عن تناقض حقيقي — اجتماع يُعرض «قادم» بينما حارس الدخول يردّ «انتهت
 * الجلسة». السبب أن الشاشات كانت تقرأ العمود المخزَّن بينما الحارس يقرأ liveState(). والعمود
 * **يتأخّر عمداً**: AutoCloseMissedMeetings يُمهل 12 ساعة قبل الحسم، وliveState موجودة تحديداً
 * لسدّ تلك الفجوة («يُعرض لم ينعقد فوراً دون انتظار المجدول»).
 *
 * ولماذا فئة لا إصلاح في كل ملفّ: العطل نشأ من ثلاث نسخ تباعدت (تقويم العميل · الموظف ·
 * المحامي). إصلاح كلٍّ على حدة يُعيد إنتاج السبب نفسه.
 *
 * **لا اشتقاق هنا**: كل دالّة تنادي البانِي الموثوق في النموذج ولا تحسب شيئاً بنفسها.
 */
class EventStatus
{
    /** جلسة «مجدولة» فات موعدها — نصّ موحّد يستعمله العرض والنغمة. */
    public const HEARING_LAPSED = 'فائتة — بانتظار النتيجة';

    /** استشارة فاتت بلا جلسة — كانت «بانتظار الجلسة» أبدية متناقضة مع «لم يحضر» في المواعيد. */
    public const CONSULT_MISSED = 'لم تنعقد';

    /** المصدر: Meeting::liveState — «قادم» الفائت يصير «لم ينعقد» فوراً. */
    public static function forMeeting(Meeting $meeting): string
    {
        return $meeting->liveState()[1];
    }

    /** المصدر: CaseHearing::isLapsed. */
    public static function forHearing(CaseHearing $hearing): string
    {
        return $hearing->isLapsed() ? self::HEARING_LAPSED : (string) $hearing->status;
    }

    /**
     * المصدر: Consult::isMissed ثمّ حالة الجلسة.
     * `session` أدقّ من `status` للعرض الزمني: الأخيرة تصف مسار الطلب (جديدة/مسعّرة) لا الجلسة.
     */
    public static function forConsult(Consult $consult): string
    {
        if ($consult->isMissed()) {
            return self::CONSULT_MISSED;
        }

        return (string) ($consult->session ?: $consult->status);
    }

    /** نغمة الحالة للأنواع غير الاجتماعات (الاجتماع له meetStatusTone في الواجهة). */
    public static function toneFor(string $status): string
    {
        return in_array($status, [self::HEARING_LAPSED, self::CONSULT_MISSED], true) ? 'b-red' : 'b-blue';
    }
}
