<?php

namespace App\Services\Ai;

use App\Contracts\ClientConversation;
use App\Models\AiRun;
use App\Models\Execution;
use App\Models\LegalCase;
use Illuminate\Database\Eloquent\Model;

/**
 * **حالة الملفّ الآن أمام مراجِع مخرج الذكاء** (قرار المالك 2026-10-02).
 *
 * كان الصندوق يعرض المرجع والنصّ وحدهما، فيعتمد المراجعُ دراسةً لملفٍّ صار «قيد التنفيذ» أو لائحةً لقضيّةٍ أُغلقت
 * دون أن يعلم. هنا تُقرأ الحالة الحاليّة وما سيترتّب على القبول، بالقواعد نفسها التي يطبّقها `AiReviewOutcome`
 * (لا نسخة ثانية منها): `Execution::studyPublishable` · `LegalCase::acceptsReclassification` · `ClientConversation`.
 */
final class AiReviewEntityState
{
    /**
     * @return array{status: ?string, stale: ?string} `status` حالة الملفّ الآن، و`stale` أثرُ القبول حين لا يصل العميل.
     */
    public static function for(AiRun $run): array
    {
        $entity = self::entity($run);

        return [
            'status' => $entity !== null ? (string) $entity->getAttribute('status') : null,
            'stale' => $entity !== null ? self::staleNote($run->task_type, $entity) : null,
        ];
    }

    private static function staleNote(string $taskType, Model $entity): ?string
    {
        return match (true) {
            $taskType === 'execution' && $entity instanceof Execution && ! $entity->studyPublishable() => 'تجاوز الملفّ مرحلة الدراسة أو انتهى — يُسجَّل الاعتماد للمكتب ولا يُنشر للعميل.',
            $taskType === 'case.classify' && $entity instanceof LegalCase && ! $entity->acceptsReclassification() => 'حُسمت القضيّة (حكمٌ أو إغلاق) — لن يُطبَّق التصنيف المقترح.',
            $entity instanceof ClientConversation && ! $entity->isOpenForClient() => 'الملفّ منتهٍ — لن يصل العميلَ شيء.',
            default => null,
        };
    }

    /** الكيان من علاقة القيد، أو برقمه المرجعيّ للقيود التي لم تحفظ نوعه (كما يقرؤه `AiReviewOutcome`). */
    private static function entity(AiRun $run): ?Model
    {
        if ($run->entity instanceof Model) {
            return $run->entity;
        }

        return match (true) {
            $run->task_type === 'execution' => Execution::where('number', $run->entity_ref)->first(),
            str_starts_with($run->task_type, 'case.') => LegalCase::where('number', $run->entity_ref)->first(),
            default => null,
        };
    }
}
