<?php

namespace App\Enums;

/**
 * مصدر أي مخرج «ذكيّ» — الفارق بين تحليل أجراه نموذج فعلاً وقالبٍ حتميّ عاد عند تعذّره.
 *
 * كان هذا الفارق غير موجود في البيانات: `analyzeExecution` تعيد عند الفشل نفس شكل
 * النجاح حرفياً، فيضبط `ExecService::applyAnalysis` علم `ai_done=true` ويُشعر العميل
 * بأن «الذكاء الاصطناعي حلّل الطلب» بينما لم يُفحص مستند واحد. هذا التعداد هو مصدر
 * الحقيقة الوحيد للتمييز، ويُخزَّن مع كل مخرج ويُعرض في الواجهة.
 */
enum AiSource: string
{
    /** تحليل فعليّ من نموذج، اجتاز التحقّق البرمجيّ. */
    case AiSuccess = 'ai_success';

    /** قالب حتميّ عاد عند تعذّر النموذج — تقييم أوّليّ لا تحليل. */
    case Fallback = 'fallback';

    /** تعذّرت المعالجة الآلية أصلاً ويلزم عمل بشريّ. */
    case ManualRequired = 'manual_required';

    /** راجعه إنسان مفوَّض واعتمده — أعلى درجات الثقة. */
    case HumanApproved = 'human_approved';

    /** هل هذا المصدر تحليلاً ذكياً فعلياً؟ (ما عداه لا يجوز عرضه كذلك) */
    public function isRealAnalysis(): bool
    {
        return $this === self::AiSuccess || $this === self::HumanApproved;
    }

    /** التسمية العربية المعروضة للمستخدم — لا تدّعي أكثر ممّا جرى. */
    public function label(): string
    {
        return match ($this) {
            self::AiSuccess => 'تحليل بالذكاء الاصطناعي',
            self::Fallback => 'تقييم أوّليّ آليّ محدود',
            self::ManualRequired => 'يتطلّب مراجعة يدوية',
            self::HumanApproved => 'معتمد بمراجعة بشرية',
        };
    }
}
