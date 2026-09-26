<?php

namespace App\Enums;

/**
 * **من حكم بأنّ مستنداً مرفقاً يستوفي بنداً من قائمة مستندات القسم** — ذكاءٌ أم موظّف.
 *
 * يُخزَّن مع كلّ مطابقة (`ticket_document_requirements.checked_by`) كي يعرف الطاقم مصدر «✓»:
 * حكمُ نموذجٍ قرأ المستند يختلف عن تأكيد إنسانٍ اطّلع عليه. والعميل لا يرى هذا الفارق.
 */
enum RequirementCheck: string
{
    /** طابقه فحص المستند الآليّ (`document.analyze`) من محتواه. */
    case Ai = 'ai';

    /** أكّده موظّفٌ أو محامٍ أو الإدارة يدوياً. */
    case Staff = 'staff';

    /** التسمية للطاقم — لا تُعرض للعميل. */
    public function label(): string
    {
        return match ($this) {
            self::Ai => 'طابقه الفحص الآليّ',
            self::Staff => 'أكّده الفريق',
        };
    }
}
