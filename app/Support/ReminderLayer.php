<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * طبقة تذكير واحدة: نافذتها الزمنية + عمود ختمها + صياغة المدّة المتبقّية.
 *
 * لماذا: أوامر التذكير الثلاثة (استشارات · جلسات محاكم · اجتماعات) كانت منسوخة يدويّاً —
 * دالّة remainingLabel() مكرّرة حرفيّاً بين SendConsultReminders وSendHearingReminders،
 * وشرط استحقاق الطبقة مكرّر كذلك. فأي طبقة جديدة تعني نسخ المنطق مرّة رابعة، وأي تصحيح
 * في الصياغة يلزم تطبيقه في كل نسخة.
 *
 * هنا تُعرَّف الطبقة مرّة واحدة كقيمة، فتصير إضافة طبقة سطراً في مصفوفة.
 * يقرؤها أمرا الاستشارات وجلسات المحاكم، ومدد طبقاتهما من الإعدادات (`consult_reminder_*` · `hearing_reminder_*`).
 */
final class ReminderLayer
{
    /**
     * @param  string  $stampColumn  عمود الختم الذي يمنع التكرار (idempotency)
     * @param  int|null  $upperMinutes  الحدّ الأعلى للنافذة بالدقائق (null = بلا سقف)
     * @param  int  $lowerMinutes  الحدّ الأدنى — ما دونه تغطّيه قناة أخرى (مثل إطلاق الرابط)
     */
    public function __construct(
        public readonly string $stampColumn,
        public readonly ?int $upperMinutes,
        public readonly int $lowerMinutes,
    ) {}

    /**
     * هل تستحقّ هذه الطبقة الإرسال الآن لهذا الموعد؟
     *
     * @param  CarbonInterface|null  $stampedAt  قيمة عمود الختم (null = لم تُرسَل بعد)
     */
    public function isDue(CarbonInterface $now, CarbonInterface $startsAt, ?CarbonInterface $stampedAt): bool
    {
        if ($stampedAt !== null) {
            return false;
        }

        if (! $startsAt->gt($now->copy()->addMinutes($this->lowerMinutes))) {
            return false;
        }

        return $this->upperMinutes === null
            || $startsAt->lte($now->copy()->addMinutes($this->upperMinutes));
    }

    /**
     * وصف زمنيّ للوقت المتبقّي حتى الموعد (نحو X ساعة / نحو X دقيقة).
     * كان مكرّراً حرفيّاً في أمرَي الاستشارات والجلسات.
     */
    public static function remainingLabel(CarbonInterface $now, CarbonInterface $startsAt): string
    {
        $mins = (int) ceil($now->diffInMinutes($startsAt));

        if ($mins >= 120) {
            return 'نحو '.(int) round($mins / 60).' ساعة';
        }

        if ($mins >= 60) {
            return 'نحو ساعة';
        }

        return 'نحو '.max(1, $mins).' دقيقة';
    }
}
