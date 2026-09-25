<?php

namespace App\Support;

use App\Models\User;

/**
 * **اقتراح النظام لمحامي التذكرة — اقتراحٌ يؤكّده إنسان، لا إسناد** (قرار المالك 2026-09-20).
 *
 * سلسلة المالك (2026-09-25): محامٍ مختصّ بقسم التذكرة ← وإلّا محامٍ آخر **موسومٌ بأنّه غير مختصّ** ←
 * وإن لم يوجد محامٍ أصلاً فالإدارة العليا صاحبة الملفّ (`EscalateUnassignedTicketJob`).
 *
 * كان `pickLawyer` يحسب «هل وُجد مختصّ؟» ثمّ يرميه ويُعيد محامياً فقط، فشاشة التوزيع تعرض ⚡
 * «محامي العقارات» لتذكرةٍ عمّاليّة كأنّه الاختيار الطبيعيّ. هنا يبقى الوسم مع الاقتراح، ونصّ
 * العرض يُصاغ في الخادم مرّةً فلا تعيد كلُّ شاشةٍ اشتقاقه.
 */
final readonly class LawyerSuggestion
{
    public function __construct(
        public ?User $lawyer,
        /** يغطّي المحامي قسمَ التذكرة (`LawyerSpecialties::covers`). */
        public bool $specialist,
        /** للتذكرة قسمٌ معروف — بدونه لا معنى لـ«غير مختصّ». */
        public bool $departmentKnown,
        public ?string $department,
    ) {}

    /** لا محامي يُقترح (لا محامي نشطاً في وضع التوزيع الآليّ). */
    public static function none(?string $department): self
    {
        return new self(null, false, filled($department), $department);
    }

    /** نصّ العرض — للطاقم وحدهم. */
    public function label(): string
    {
        return match (true) {
            $this->lawyer === null => 'لا محامٍ متاحٌ للاقتراح — تُسنَد التذكرة للإدارة العليا إن لم يُسنِدها أحد.',
            $this->specialist => 'مقترحٌ مختصّ بقسم التذكرة (الأقلّ حملاً بين المختصّين).',
            $this->departmentKnown => 'مقترحٌ غير مختصّ — لا محامٍ متاحٌ في قسم «'.$this->department.'»؛ اقتُرح الأقلّ حملاً.',
            default => 'قسم التذكرة غير محدَّد — اقتُرح الأقلّ حملاً.',
        };
    }

    /** @return array{lawyerId: ?int, lawyerName: ?string, specialist: bool, label: string} */
    public function toArray(): array
    {
        return [
            'lawyerId' => $this->lawyer?->id,
            'lawyerName' => $this->lawyer?->name,
            'specialist' => $this->specialist,
            'label' => $this->label(),
        ];
    }
}
