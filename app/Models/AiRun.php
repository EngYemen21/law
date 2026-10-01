<?php

namespace App\Models;

use App\Enums\AiSource;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * قيد واحد لكل تشغيل ذكاء اصطناعيّ. الكتابة صامتة الفشل عمداً — السجلّ توثيق لاحق
 * ولا يجوز أن يُفشل العمليةَ التي يوثّقها (جدول مفقود قبل الهجرة، قاعدة مقفلة…)،
 * وهو نفس عقد `App\Support\Audit`.
 */
class AiRun extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    protected $fillable = [
        'task_type', 'entity_type', 'entity_id', 'entity_ref',
        'source', 'status', 'confidence', 'confidence_signals', 'outbound_audit',
        'model', 'prompt_version',
        'trace_id', 'failure_code', 'duration_ms',
        'input_tokens', 'output_tokens', 'estimated_cost',
        'reviewed_by', 'reviewed_at',
        'review_action', 'review_reason', 'review_note', 'review_edit_distance', 'escalated_to',
    ];

    protected $casts = [
        'source' => AiSource::class,
        'confidence' => 'integer',
        'confidence_signals' => 'array',
        'outbound_audit' => 'array',
        'duration_ms' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'estimated_cost' => 'decimal:6',
        'reviewed_at' => 'datetime',
        'review_action' => AiReviewAction::class,
        'review_reason' => AiReviewReason::class,
        // التشفير في السكون (P5). `review_note` هو الحقل الحرّ الوحيد في هذا الجدول،
        // وفيه يكتب المراجع لماذا رفض مخرجاً — أي وقائع ملفٍّ بلغته. وبقيّة الأعمدة
        // رموزٌ وأزمنة ومعرّفات لا محتوى، فمحلّ التشفير هذا الحقل وحده لا الجدول كلّه.
        // ولا يُبحَث فيه ولا يُرشَّح به في أي مسار، فالتشفير لا يكسر استعلاماً قائماً.
        'review_note' => 'encrypted',
    ];

    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * مفتاح منع التكرار: هل سبق أن أُنتج مخرج لهذه المهمّة على هذا الكيان؟
     *
     * الطابور يعيد تشغيل الوظيفة عند تعطّل العامل أو فشل عابر بعد تنفيذ أثرها،
     * فتتكرّر الرسائل والإشعارات والقيود. القيد نفسه هو المفتاح الدائم — لا حاجة
     * لعلم منفصل، ولا اتّكال على أثر جانبيّ مثل `ai_done` (الذي كان يحرس التنفيذ
     * بالمصادفة حتى صار الاحتياطيّ لا يدّعي اكتمالاً).
     *
     * الفشل الصامت في `record` يعني غياب المفتاح ⇒ إعادة التحليل: إخفاق نحو
     * الجانب الآمن (تحليل مكرّر خيرٌ من تحليل ضائع).
     */
    public static function alreadyRan(string $taskType, Model $entity): bool
    {
        try {
            return self::where('task_type', $taskType)
                ->where('entity_type', $entity::class)
                ->where('entity_id', $entity->getKey())
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * تسجيل تشغيل. `$entity` يُشتقّ منه النوع والمعرّف؛ و`$entityRef` الرقم المرجعيّ
     * المقروء (EX-2026-11) كي يبقى القيد مفهوماً بعد حذف الكيان.
     */
    public static function record(
        string $taskType,
        AiSource $source,
        ?Model $entity = null,
        ?string $entityRef = null,
        string $status = self::STATUS_COMPLETED,
        ?int $confidence = null,
        ?array $confidenceSignals = null,
        ?string $model = null,
        ?string $promptVersion = null,
        ?string $traceId = null,
        ?string $failureCode = null,
        ?int $durationMs = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?float $estimatedCost = null,
        ?array $outboundAudit = null,
    ): ?self {
        try {
            return self::create([
                'task_type' => $taskType,
                'entity_type' => $entity ? $entity::class : null,
                'entity_id' => $entity?->getKey(),
                'entity_ref' => $entityRef,
                'source' => $source->value,
                'status' => $status,
                // درجة مشتقّة خادمياً مع إشاراتها — الدرجة بلا أساسها غير قابلة للتدقيق
                'confidence' => $confidence,
                'confidence_signals' => $confidenceSignals,
                // دليل تقليل البيانات: أعداد المعرّفات المموَّهة وحجم الحمولة التي
                // غادرت الخادم — لا محتوى، فالسجلّ إثباتٌ لا نسخةٌ ثانية من البيانات
                'outbound_audit' => $outboundAudit,
                // النموذج الفعليّ المستخدم — يبقى null للاحتياطيّ لأنه لم يُستدعَ نموذج أصلاً
                'model' => $model,
                'prompt_version' => $promptVersion,
                'trace_id' => $traceId ?: (string) Str::uuid(),
                'failure_code' => $failureCode,
                'duration_ms' => $durationMs,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                // null = لا سعر مُهيَّأ للنموذج، لا «مجّانيّ»
                'estimated_cost' => $estimatedCost,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AiRun] تعذّر تسجيل تشغيل الذكاء: '.$e->getMessage());

            return null;
        }
    }
}
