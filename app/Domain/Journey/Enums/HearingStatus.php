<?php

namespace App\Domain\Journey\Enums;

use App\Support\EventStatus;

/**
 * **حالة جلسة المحكمة المخزّنة** — مصدرٌ واحد لقيمها ولما يجوز على كلٍّ منها.
 *
 * كانت الحرّاس تقارن نصوصاً عربيّة متناثرة (`in_array($status, ['منعقدة', 'ملغاة'])`)، وكلُّ حارسٍ
 * يقرّر بنفسه أيّ الحالات «منتهية». وهكذا أمكن «إعادة جدولة» جلسةٍ سُجّلت مؤجّلةً فمُحي تأجيلها.
 * هنا يُقرَّر مرّةً: ما ينتظر نتيجة، وما صار سجلّاً لما جرى.
 */
enum HearingStatus: string
{
    case Scheduled = 'مجدولة';

    /**
     * **مخزّنةٌ لا معروضةٌ فقط:** `hearings:auto-lapse` يكتبها في العمود بعد يومٍ من الموعد بلا نتيجة.
     * وقيمتها من `EventStatus` لأنّ العرض والنغمة يقرآنها من هناك — نصٌّ واحد لا نسختان.
     */
    case Lapsed = EventStatus::HEARING_LAPSED;

    case Held = 'منعقدة';
    case Postponed = 'مؤجلة';
    case Cancelled = 'ملغاة';

    /** للقيم المخزّنة القديمة أو الغريبة: `null` لا استثناء — الحارس يرفض بسببه لا بخطأ 500. */
    public static function of(?string $value): ?self
    {
        return self::tryFrom((string) $value);
    }

    /** لم تُسجَّل نتيجتها بعد (مجدولة، أو فات موعدها) — تُسجَّل نتيجتها وتُلغى. */
    public function awaitsOutcome(): bool
    {
        return $this === self::Scheduled || $this === self::Lapsed;
    }

    /**
     * انعقدت أو أُلغيت — لا تُعدَّل بياناتها ولا يُحرَّك موعدها.
     * و«المؤجّلة» ليست منها: يُحدَّد منها موعد الجلسة التالية ما لم تُحدَّد بعد.
     */
    public function isFinal(): bool
    {
        return $this === self::Held || $this === self::Cancelled;
    }

    /**
     * **نغمة الشارة** — نظير `hearingTone` في `lib/case-ui.tsx` (اللوحة نفسها). يرسلها الخادم لشاشة
     * جلسات الإدارة بدل `switch` على النصوص العربيّة هناك، الذي كان يلوّن «مؤجلة» بنفسجيّاً و«فائتة»
     * عنبريّاً خلافاً لبقيّة الشاشات.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Held => 'b-green',
            self::Postponed => 'b-amber',
            self::Cancelled => 'b-grey',
            self::Lapsed => 'b-red',
            self::Scheduled => 'b-blue',
        };
    }

    /**
     * النتائج التي يسجّلها المحامي أو الموظّف لجلسةٍ انتظرت نتيجتها — قاعدة `in:` للتحقّق.
     *
     * @return list<string>
     */
    public static function outcomes(): array
    {
        return [self::Held->value, self::Postponed->value];
    }
}
