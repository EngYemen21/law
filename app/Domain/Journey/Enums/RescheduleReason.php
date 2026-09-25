<?php

namespace App\Domain\Journey\Enums;

/**
 * **لماذا أُعيدت جدولة الموعد — قائمةٌ مغلقة لا نصٌّ حرّ.** (قرار المالك 2026-09-25)
 *
 * كانت إعادة الجدولة تقع بلا سبب: خانة `reason` في سجلّ الرحلة موجودة وتبقى فارغة، فلا
 * يُعرف بعدها هل طلبها العميل أم اعتذر المحامي أم تعثّر الاتّصال. والسبب الحرّ وحده لا
 * يُحصى ولا يُصفّى؛ فالقائمة مغلقة، و«سببٌ آخر» يُلزم بشرح.
 *
 * ويخدم الاستشارات والاجتماعات وجلسات المحكمة معاً: المعنى واحد في المجالات الثلاثة.
 */
enum RescheduleReason: string
{
    case ClientRequest = 'client_request';
    case LawyerUnavailable = 'lawyer_unavailable';
    case Technical = 'technical';
    case ClientAbsent = 'client_absent';
    case CourtDecision = 'court_decision';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ClientRequest => 'بطلب العميل',
            self::LawyerUnavailable => 'اعتذار المحامي',
            self::Technical => 'عطلٌ فنّيّ',
            self::ClientAbsent => 'غياب العميل عن الجلسة',
            self::CourtDecision => 'قرار المحكمة',
            self::Other => 'سببٌ آخر',
        };
    }

    /** «سببٌ آخر» بلا شرح لا يفيد أحداً — فيُلزَم بنصّ. */
    public function needsNote(): bool
    {
        return $this === self::Other;
    }

    /**
     * الأسباب المعروضة لكلّ مجال — «قرار المحكمة» لا معنى له في استشارة.
     *
     * @return list<self>
     */
    public static function for(string $domain): array
    {
        return match ($domain) {
            'hearing' => [self::CourtDecision, self::LawyerUnavailable, self::ClientRequest, self::Other],
            default => [self::ClientRequest, self::LawyerUnavailable, self::Technical, self::ClientAbsent, self::Other],
        };
    }

    /**
     * قائمة الواجهة — قيمةٌ ونصّ وهل يلزم شرح. مصدرٌ واحد: الواجهة لا تكتب الأسباب بنفسها.
     *
     * @return list<array{value:string,label:string,needsNote:bool}>
     */
    public static function options(string $domain): array
    {
        return array_map(
            fn (self $r) => ['value' => $r->value, 'label' => $r->label(), 'needsNote' => $r->needsNote()],
            self::for($domain)
        );
    }

    /**
     * قواعد التحقّق للسبب وشرحه — موضعٌ واحد لكلّ متحكّم.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $domain): array
    {
        $allowed = array_map(fn (self $r) => $r->value, self::for($domain));

        return [
            'reason' => ['required', 'string', 'in:'.implode(',', $allowed)],
            'note' => ['nullable', 'string', 'max:500', 'required_if:reason,'.self::Other->value],
        ];
    }

    /** السطر الذي يُحفظ في سجلّ الرحلة والتدقيق: «اعتذار المحامي — سافر فجأة». */
    public function describe(?string $note): string
    {
        $note = trim((string) $note);

        return $note === '' ? $this->label() : $this->label().' — '.$note;
    }
}
