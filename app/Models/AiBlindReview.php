<?php

namespace App\Models;

use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حكم محامٍ على مخرجٍ **دون أن يعرف مصدره** — الطبقة الثالثة.
 *
 * القيد يحفظ حكم المحامي وحكم الآلة معاً، فيصير سؤال الخطة قابلاً للإجابة:
 * هل يتّفقان؟ **واختلافهما يكشف عيباً في المعايير لا في النموذج** — إذ قد تكون
 * البوّابة تُصعّد ما يقبله المحامي (تشدّدٌ يكلّف وقتاً) أو تقبل ما يرفضه (تساهلٌ يكلّف
 * ملفّاً).
 */
class AiBlindReview extends Model
{
    protected $fillable = [
        'ai_run_id', 'reviewer_id',
        'verdict', 'reason', 'note', 'judged_at', 'revealed_at',
        'machine_status', 'machine_confidence',
    ];

    protected $casts = [
        'verdict' => AiReviewAction::class,
        'reason' => AiReviewReason::class,
        'judged_at' => 'datetime',
        'revealed_at' => 'datetime',
        'machine_confidence' => 'integer',
        // ملاحظة المحامي حقلٌ حرّ قد يحمل وقائع ملفّ بلغته — تُشفَّر كنظيرتها في ai_runs
        'note' => 'encrypted',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** هل حكم المحامي بعد؟ */
    public function judged(): bool
    {
        return $this->judged_at !== null;
    }

    /**
     * حكم الآلة بلغة المراجع.
     *
     * كان يُعرض خاماً (`completed`) أمام محامٍ — مصطلحٌ تقنيّ لا يقول له شيئاً، وهو
     * الطرف الذي تُقارَن به شهادته. والمقارنة لا تصحّ إن لم يفهم ما يُقارَن.
     */
    public function machineVerdict(): string
    {
        return match ($this->machine_status) {
            AiRun::STATUS_COMPLETED => 'قَبِله النظام آلياً',
            AiRun::STATUS_NEEDS_REVIEW => 'صعّده النظام للمراجعة',
            AiRun::STATUS_FAILED => 'رفضه النظام',
            default => (string) $this->machine_status,
        };
    }

    /**
     * هل بقي الحكم أعمى فعلاً؟
     *
     * الحكم قبل الكشف أعمى؛ وحكمٌ سُجِّل بعده ليس شهادةً مستقلّة مهما سُمّي.
     */
    public function wasBlind(): bool
    {
        return $this->judged() && ($this->revealed_at === null || $this->judged_at->lte($this->revealed_at));
    }

    /**
     * هل اتّفق المحاميّ والآلة؟ `null` = لم يحكم بعد.
     *
     * القبول البشريّ يقابله قبولٌ آليّ (`completed`)؛ وما عداه — تعديل أو رفض —
     * يقابله تصعيد. و«تعديل ثم قبول» يُعدّ خلافاً مع القبول الآليّ: مخرجٌ احتاج يد
     * إنسان لم يكن صالحاً لأن يمرّ بلا مراجعة.
     */
    public function agrees(): ?bool
    {
        if (! $this->judged()) {
            return null;
        }

        $humanAccepted = $this->verdict === AiReviewAction::Accept;
        $machineAccepted = $this->machine_status === AiRun::STATUS_COMPLETED;

        return $humanAccepted === $machineAccepted;
    }
}
