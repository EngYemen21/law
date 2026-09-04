<?php

namespace App\Support\Booking;

use App\Support\MeetingTime;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * **لحظةُ حجزٍ واحدة** — تعريفٌ واحد لِما يُقبل موعداً في المشروع كلّه.
 *
 * كان للمشروع طبقتا تحقّق لنفس المفهوم:
 *
 * - **صارمة** (`consults.schedule` · `schedule` · `meetreqs`): `date` + صيغة وقت
 *   + رفض الماضي + فحص تعارض.
 * - **حرّة** (`meetings` إنشاءً وإعادةَ جدولة · `hearings` إضافةً وتحديثاً): سلاسل
 *   بلا قاعدة `date` ولا فحص ماضٍ. فيُقبل «الاثنين القادم» و«أمس»، ويُفكّ بأفضل
 *   جهد، ويُخزَّن `starts_at = null` عند التعذّر.
 *
 * و`null` هنا ليس نقصاً في بيانات العرض: `meetings:send-reminders` و
 * `hearings:send-reminders` يشترطان `starts_at`، فالموعد الذي تعذّر فكّه **لا
 * يصله تذكيرٌ أبداً** — صامتاً، بلا خطأ ولا أثر. وهذا ما يجعل الطبقة الحرّة عطلاً
 * لا تساهلاً.
 *
 * **والقاعدة التي يفرضها هذا الصنف:** كل طلبٍ مقبول يُنتج لحظةً حقيقيّة. وما تعذّر
 * فكّه **يُرفض برسالة** بدل أن يُكتب `null` صامتاً. وسلاسل العرض تُشتقّ من اللحظة
 * لا من المدخل الخام، فلا تتباعد الواجهة عن الحقيقة.
 */
final class BookingMoment
{
    private function __construct(
        public readonly Carbon $startsAt,
        public readonly int $durationMin,
    ) {}

    /**
     * قواعد التحقّق المشتركة — تُدمج في `validate()` بأيّ سطح.
     *
     * `date_format` لا `date`: الثانية تقبل «tomorrow» و«+1 week»، والأولى تفرض
     * الشكل الذي يُرسله حقل التاريخ فعلاً. و`H:i` يرفض `99:99` و`12:60` معاً —
     * وهو ما لم يكن `regex:/^\d{2}:\d{2}$/` يفعله.
     *
     * @param  bool  $timeRequired  الوقت إلزاميّ؟ (الجلسات القضائية قد تُجدول بلا ساعة)
     * @return array<string, array<int,string>>
     */
    public static function rules(bool $timeRequired = true): array
    {
        return [
            'day' => ['required', 'date_format:Y-m-d'],
            'time' => [$timeRequired ? 'required' : 'nullable', 'date_format:H:i'],
        ];
    }

    /** ونظيرها حين يُسمّى الحقل `date` (مسار العميل). */
    public static function dateRules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * يبني اللحظة من يومٍ ووقت — ويرمي رسالةً عربيّة إن تعذّر.
     *
     * **الرمي لا الإرجاع `null`:** الإرجاع الصامت هو ما أنتج صفوف `starts_at`
     * الفارغة. ومن أراد التساهل فليُعلنه بـ`tryFrom`.
     */
    public static function from(?string $day, ?string $time, int $durationMin = 60, string $field = 'day'): self
    {
        $moment = self::tryFrom($day, $time, $durationMin);

        if ($moment === null) {
            throw ValidationException::withMessages([
                $field => 'تعذّر فهم الموعد المُدخل — أدخل تاريخاً بصيغة سنة-شهر-يوم ووقتاً بصيغة ساعة:دقيقة.',
            ]);
        }

        return $moment;
    }

    /** محاولةٌ متساهلة — تُستعمل حيث يكون غياب الموعد حالةً مشروعة. */
    public static function tryFrom(?string $day, ?string $time, int $durationMin = 60): ?self
    {
        $parsed = MeetingTime::parse($day, $time);

        if ($parsed === null) {
            return null;
        }

        return new self(Carbon::instance($parsed->toDateTime()), max(1, $durationMin));
    }

    public function endsAt(): Carbon
    {
        return $this->startsAt->copy()->addMinutes($this->durationMin);
    }

    /** `Y-m-d` — الصيغة التي تُخزَّن في أعمدة `day`. */
    public function dayString(): string
    {
        return $this->startsAt->format('Y-m-d');
    }

    /** `H:i` — الصيغة التي تُخزَّن في أعمدة `time`. */
    public function timeString(): string
    {
        return $this->startsAt->format('H:i');
    }

    /**
     * سلسلة العرض — **مشتقّة لا مأخوذة من المدخل**.
     *
     * كان `when_label` يُخزَّن من السلسلة الخام، فيقول «الاثنين القادم» بينما
     * `starts_at` يقول شيئاً آخر أو لا يقول شيئاً.
     */
    public function label(): string
    {
        return $this->dayString().' · '.$this->timeString();
    }

    public function isPast(): bool
    {
        return $this->startsAt->isPast();
    }
}
