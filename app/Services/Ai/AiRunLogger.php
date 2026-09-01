<?php

namespace App\Services\Ai;

use App\Enums\AiSource;
use App\Models\AiRun;
use Illuminate\Database\Eloquent\Model;

/**
 * تسجيل قيد القرار: بوّابة السياسة ثم `AiRun::record` — في موضعٍ واحد.
 *
 * كان النداء يُكرَّر بستّة عشر سطراً في كل موضع أعمال (`ExecService` و`TicketTriage`
 * و`ConsultController` والوظائف)، وفي كلٍّ منها تُفكَّك `$meta` يدوياً بالمفاتيح
 * نفسها. وتكرارٌ بهذا الطول لا يبقى متطابقاً: حقلٌ يُضاف إلى `callMeta` — كما وقع
 * مع `outbound_audit` ثم `residual` — يصل بعض المواضع دون بعض، فيصير السجلّ ناقصاً
 * في مسارات ولا يُلحَظ. وهذا الصنف هو المكان الذي يُضاف فيه الحقل مرّةً واحدة.
 *
 * ولا يُقرِّر شيئاً من عنده: الحالة من `AiPolicyGate` كما كانت، والفشل الصامت من
 * `AiRun::record` كما هو — قيدٌ متعذّر لا يُسقط عملاً قانونياً جارياً.
 */
class AiRunLogger
{
    /**
     * @param  string  $taskType  اسم المهمّة كما يُخزَّن في `ai_runs`
     * @param  array<string,mixed>  $meta  مخرج `LegalAiService::callMeta`
     * @param  string|null  $policyTask  معرّف التعليمة لبوّابة السياسة إن خالف `$taskType`
     *                                   (مثال: القيد `execution` وتعليمته `execution.analyze`)
     * @param  bool  $keepFailureCode  رمز العطل يُحفظ حتى مع مخرجٍ ناجح؟ الافتراض: لا
     *                                 يُحفظ إلا حين لا يكون المصدر تحليلاً حقيقياً —
     *                                 وإلّا قُرئ نجاحٌ بعد إعادة محاولة عطلاً.
     */
    public static function log(
        string $taskType,
        AiSource $source,
        array $meta = [],
        ?Model $entity = null,
        ?string $entityRef = null,
        ?string $policyTask = null,
        bool $keepFailureCode = false,
    ): ?AiRun {
        $decision = AiPolicyGate::decide(
            taskType: $policyTask ?? $meta['prompt_id'] ?? $taskType,
            source: $source,
            confidence: $meta['confidence'] ?? null,
        );

        return AiRun::record(
            taskType: $taskType,
            source: $source,
            entity: $entity,
            entityRef: $entityRef,
            status: $decision->status(),
            confidence: $meta['confidence'] ?? null,
            confidenceSignals: $meta['confidence_signals'] ?? null,
            model: $meta['model'] ?? null,
            promptVersion: $meta['prompt_version'] ?? null,
            traceId: $meta['trace_id'] ?? null,
            failureCode: ($keepFailureCode || ! $source->isRealAnalysis())
                ? ($meta['failure_code'] ?? null)
                : null,
            durationMs: $meta['duration_ms'] ?? null,
            inputTokens: $meta['input_tokens'] ?? null,
            outputTokens: $meta['output_tokens'] ?? null,
            estimatedCost: $meta['estimated_cost'] ?? null,
            outboundAudit: $meta['outbound_audit'] ?? null,
        );
    }
}
