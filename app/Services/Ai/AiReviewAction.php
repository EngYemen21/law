<?php

namespace App\Services\Ai;

/**
 * أفعال المراجع الخمسة المنفصلة التي تفرضها الخطة: قبول · تعديل · رفض ·
 * طلب إعادة تشغيل · تصعيد.
 *
 * فصلها مقصود: «قبول» و«تعديل ثم قبول» ليسا شيئاً واحداً. الأوّل يقول إن المخرج
 * صحيح كما هو، والثاني يقول إنه احتاج يد إنسان — والفارق بينهما هو **مقياس الجودة
 * الحقيقيّ**. دمجهما في زرّ «اعتماد» واحد (كما هو الحال اليوم في ملخّص التذكرة
 * وتحليل الاستشارة) يُخفي كم مرّة أصلح الإنسان مخرجاً قبل أن يعتمده.
 */
enum AiReviewAction: string
{
    /** صحيح كما هو — بلا تعديل. */
    case Accept = 'accept';

    /** قُبل بعد تعديل بشريّ — يُحفظ الفرق. */
    case Edit = 'edit';

    /** مرفوض — يلزمه سبب منظَّم. */
    case Reject = 'reject';

    /** طلب إعادة تشغيل (تعذّر مؤقّت أو سياق تغيّر). */
    case Rerun = 'rerun';

    /** تصعيد لزميل متخصّص أو للإدارة. */
    case Escalate = 'escalate';

    public function label(): string
    {
        return match ($this) {
            self::Accept => 'قبول',
            self::Edit => 'تعديل واعتماد',
            self::Reject => 'رفض',
            self::Rerun => 'إعادة تشغيل',
            self::Escalate => 'تصعيد',
        };
    }

    /** هل يُنهي هذا الفعل المراجعة؟ (الإعادة والتصعيد يُبقيانها مفتوحة) */
    public function closesReview(): bool
    {
        return in_array($this, [self::Accept, self::Edit, self::Reject], true);
    }

    /** الرفض وحده يُلزم بسبب منظَّم — وبدونه لا يُحفظ القرار. */
    public function requiresReason(): bool
    {
        return $this === self::Reject;
    }

    /** التصعيد وحده يلزمه مُصعَّدٌ إليه. */
    public function requiresAssignee(): bool
    {
        return $this === self::Escalate;
    }

    /** @return array<int, array{value:string,label:string,requires_reason:bool,requires_assignee:bool}> */
    public static function options(): array
    {
        return array_map(fn (self $a) => [
            'value' => $a->value,
            'label' => $a->label(),
            'requires_reason' => $a->requiresReason(),
            'requires_assignee' => $a->requiresAssignee(),
        ], self::cases());
    }
}
