<?php

namespace App\Services\Ai;

/**
 * حصيلة نداء واحد للنموذج عبر البوّابة — النصّ ومعه بيانات التتبّع الحقيقيّة.
 *
 * كانت `LegalAiService::run()` تعيد `?string` مجرّداً، فلا يعرف المستهلك أيّ مزوّد
 * أجاب ولا كم استغرق ولا لماذا فشل. فحين سُجّلت مخرجات الذكاء في `ai_runs` كان
 * النموذج يُخمَّن من التهيئة والزمن يُترك فارغاً و`trace_id` يُولَّد بلا صلة بالنداء.
 */
final class AiCallResult
{
    /**
     * @param  string|null  $text  نصّ النموذج، أو null عند تعذّر كل المزوّدين
     * @param  string|null  $provider  'gemini' أو 'glm' — من أجاب فعلاً
     * @param  string|null  $model  اسم النموذج الفعليّ لا المُهيَّأ
     * @param  string|null  $failureCode  سبب معياريّ بلا أسرار ولا محتوى
     */
    public function __construct(
        public readonly ?string $text,
        public readonly string $traceId,
        public readonly int $durationMs,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?string $failureCode = null,
        public readonly ?AiUsage $usage = null,
        /**
         * @var array{chars:int,masked:array<string,int>}|null بصمة الحمولة الخارجة —
         *                                                     دليل تقليل البيانات
         */
        public readonly ?array $outboundAudit = null,
    ) {}

    /** نسخة تحمل بصمة الحمولة — تُضاف عند الحدّ حيث تُقاس، لا عند إنشاء الحصيلة. */
    public function withOutboundAudit(?array $audit): self
    {
        return new self(
            text: $this->text,
            traceId: $this->traceId,
            durationMs: $this->durationMs,
            provider: $this->provider,
            model: $this->model,
            failureCode: $this->failureCode,
            usage: $this->usage,
            outboundAudit: $audit,
        );
    }

    /** الكلفة التقديريّة، أو `null` حين لا سعر مُهيَّأ — لا صفر مضلِّل. */
    public function estimatedCost(): ?float
    {
        return AiCost::estimate($this->model, $this->usage);
    }

    public function succeeded(): bool
    {
        return $this->text !== null && trim($this->text) !== '';
    }
}
