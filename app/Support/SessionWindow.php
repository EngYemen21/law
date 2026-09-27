<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * **متى تُفتح الجلسة، ومتى تُعدّ فائتة، ومتى تُعدّ منسيّة — لا متى «تنتهي».**
 *
 * قرار المالك (2026-09-26): جلسة الاستشارة والاجتماع **بلا مدّةٍ ثابتة** — تنتهي حين تُنهى
 * (زرّ «إنهاء» لدى الطاقم أو حدث `meeting.ended` من Zoom عبر انتقال الإنهاء)، لا حين يبلغ
 * الساعةُ `البداية + المدّة`. وكانت تلك الحسبة منسوخةً في ثلاثة نماذج وأمرٍ مجدول بسقوفٍ
 * متباينة (+30 · +120 · +180 · +3س)، فتُغلق غرفةٌ ما زال فيها موكّلٌ مع محاميه، وتُعرض
 * جلسةٌ جارية «منتهية» لأنّ الساعة قالت ذلك.
 *
 * فالنهاية **حدثٌ لا حساب**، وما بقي هنا أرقامٌ لها معنى آخر صريح، كلّها تُقاس من **البداية**:
 *
 * - **فتح الدخول** قبل الموعد بدقائق (القاعدة القائمة).
 * - **الفوات**: جلسةٌ لم تبدأ قطّ بعد مهلةٍ من موعدها — كشفُ غيابٍ، لا إنهاءُ جلسةٍ بدأت.
 * - **النسيان**: جلسةٌ بدأت ولم يُنهها أحد بعد ساعاتٍ كثيرة — شبكةُ أمانٍ **تنبّه الطاقم مرّةً
 *   واحدة وتُنهيها** في النظام وفي Zoom (`sessions:close-stale`، قرار المالك 2026-09-26 الثاني —
 *   ينقض الأوّل «تنبيهٌ بلا إنهاء»: غرفةٌ منسيّة تبقى مفتوحة وتسجيلها يستهلك الحصّة).
 * - **الرقم الاسميّ** لـZoom (يشترط حقل `duration`) ولـ`DTEND` في التقويم: طول شريحة الحجز،
 *   ولا يُنهي Zoom الاجتماع به.
 *
 * كلّ ذلك مصدرٌ واحد هنا؛ ويحرس `SessionEndsByEventTest` ألّا تعود حسبة «البداية + المدّة».
 */
final class SessionWindow
{
    /** يُفتح الدخول قبل الموعد بهذه الدقائق — قاعدة `zoom:release-links` ونافذة الاجتماع. */
    public const JOIN_OPENS_BEFORE_MINUTES = 5;

    /** **الافتراض المُعلَن** لـ`session_missed_after_minutes` — القيمة النافذة من الإعدادات. */
    public const MISSED_AFTER_MINUTES = 60;

    /** **الافتراض المُعلَن** لـ`session_stale_minutes` — ست ساعات. */
    public const STALE_AFTER_MINUTES = 360;

    /** **الافتراض المُعلَن** لـ`meeting_autoclose_minutes` — ١٢ ساعة (كانت منقوشةً في `zoom:auto-close-missed`). */
    public const MEETING_AUTOCLOSE_MINUTES = 720;

    /** السبب المكتوب في سطر التنبيه بسجلّ الرحلة وفي نصّ تنبيه الطاقم. */
    public const STALE_ALERT_REASON = 'بدأت الجلسة ولم يُنهها أحد';

    /**
     * سببُ الإنهاء الآليّ للجلسة المنسيّة — يُكتب في سطر `consult.end` / `meeting.end` بسجلّ الرحلة
     * (قرار المالك 2026-09-26 الثاني: المنسيّة تُنبَّه **وتُنهى** في النظام وفي Zoom).
     */
    public const STALE_END_REASON = 'أُنهيت آليّاً لعدم إنهائها';

    /*
     * **أسباب رفض الدخول — نصٌّ واحد لكلّ سبب.** يقرؤها `joinBlocker()` في النموذجين، فيصل النصّ
     * نفسه من غرفة العميل وغرفة الطاقم ونقطة توقيع Zoom — كانت كلّ واحدةٍ تصوغ رفضها بنفسها،
     * ونقطة التوقيع تقول «انتهت نافذة دخول الجلسة أو لم تُفتح بعد» لسببين متعاكسين.
     */
    public const REFUSE_ENDED = 'انتهت الجلسة — لم يعد الدخول متاحاً.';

    public const REFUSE_MISSED = 'فاتت الجلسة — لم تنعقد في موعدها.';

    public const REFUSE_CANCELLED = 'أُلغيت هذه الجلسة.';

    public const REFUSE_NOT_VIDEO = 'هذه الاستشارة ليست مرئية — لا غرفة لها.';

    /** ما زالت في دورة الحجز (تسعير · سداد · موعدٌ لم يُعتمد) — لا غرفة قبل اعتماد الموعد. */
    public const REFUSE_BOOKING = 'لم يُعتمد موعد هذه الجلسة بعد — يُفتح الدخول بعد اعتماد الموعد.';

    /** لم يُفتح الباب بعد — والمهلة بوحدتها الطبيعيّة من الثابت نفسه لا منقوشةً في النصّ. */
    public static function refuseNotOpen(): string
    {
        return 'لم تُفتح الغرفة بعد — تُفتح قبل الموعد بـ'.ArabicCount::duration(self::JOIN_OPENS_BEFORE_MINUTES).'.';
    }

    /** مهلة الفوات بالدقائق من الموعد — للجلسة التي **لم تبدأ** وحدها. */
    public static function missedAfterMinutes(): int
    {
        return SettingsRegistry::int('session_missed_after_minutes');
    }

    /**
     * **فاتت دون أن تبدأ؟** — تُسأل عن جلسةٍ لم تبدأ وحدها؛ الجارية لا «تفوت» مهما طالت.
     * بلا موعدٍ ⇒ لا فوات (استشارةٌ تُبدأ يدوياً).
     */
    public static function isMissed(?CarbonInterface $startsAt): bool
    {
        return $startsAt !== null
            && $startsAt->copy()->addMinutes(self::missedAfterMinutes())->isPast();
    }

    /** هل فُتح باب الدخول؟ (قبل الموعد بـ`JOIN_OPENS_BEFORE_MINUTES`) — بلا موعدٍ ⇒ مفتوح. */
    public static function joinOpened(?CarbonInterface $startsAt): bool
    {
        return $startsAt === null
            || now()->greaterThanOrEqualTo($startsAt->copy()->subMinutes(self::JOIN_OPENS_BEFORE_MINUTES));
    }

    /** مهلة النسيان بالدقائق — جلسةٌ بدأت ولم تُنهَ بعدها يُنبَّه الطاقم بها وتُنهى آليّاً. */
    public static function staleAfterMinutes(): int
    {
        return SettingsRegistry::int('session_stale_minutes');
    }

    /**
     * **بدأت ولم يُنهها أحد منذ مهلة النسيان؟** — تُقاس من لحظة البدء المعروفة (أوّل دخولٍ
     * سجّله Zoom، وإلّا الموعد). بلا لحظةٍ معروفة ⇒ لا حكم (لا تنبيه بجلسةٍ لا نعرف متى بدأت).
     */
    public static function isStale(?CarbonInterface $startedAt): bool
    {
        return $startedAt !== null
            && $startedAt->copy()->addMinutes(self::staleAfterMinutes())->isPast();
    }

    /** مهلة حسم الاجتماع الذي لم ينعقد (دقائق من موعده). */
    public static function meetingAutocloseMinutes(): int
    {
        return SettingsRegistry::int('meeting_autoclose_minutes');
    }

    /**
     * **الطول الاسميّ بالدقائق — لـZoom والتقويم وحدهما.**
     *
     * Zoom يشترط `duration` عند إنشاء الاجتماع وتحديثه، وملفّ iCalendar يشترط `DTEND`. والرقم
     * هنا اسميّ: Zoom **لا يُنهي** الاجتماع عند بلوغه، ولا يقرؤه منطقُ الانتهاء في المنصّة.
     * مصدره طول شريحة الحجز (`consult_slot_minutes`) — المسافة بين موعدين لا عمرُ الجلسة.
     */
    public static function nominalMinutes(): int
    {
        return LawyerAvailability::slotMinutes();
    }
}
