<?php

namespace App\Jobs;

use App\Models\AiRun;
use App\Models\Execution;
use App\Services\LegalAiService;
use App\Support\ExecService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * التحليل الذكيّ لطلب التنفيذ (تدفّق البطاقات) بالخلفية — نظير GenerateExecutionReplyJob.
 * ينادي LegalAiService::analyzeExecution ثمّ يطبّق النتيجة عبر ExecService::applyAnalysis.
 */
class AnalyzeExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Execution $execution) {}

    public function handle(LegalAiService $ai): void
    {
        $exec = $this->execution->fresh(['documents']);
        // idempotent: لا نعيد التحليل بعد اكتماله أو بعد تجاوز مرحلة التحليل.
        // ⚠️ `ai_done` وحده لم يعد كافياً: كان الاحتياطيّ يضبطه `true` فيحرس الإعادة
        // بالمصادفة، ثم صار لا يدّعي اكتمالاً (P0) فسقط الحارس. `AiRun` هو المفتاح
        // الدائم: وجود قيدٍ للمهمّة على هذا الطلب يعني أن مخرجاً أُنتج فعلاً.
        if ($exec === null || $exec->ai_done || (int) $exec->stage > 1 || AiRun::alreadyRan('execution', $exec)) {
            return;
        }

        // تحليل وتصنيف كل مستند مرفق بالذكاء الاصطناعي
        foreach ($exec->documents as $doc) {
            if (empty($doc->summary)) {
                $docAnalysis = $ai->analyzeExecutionDocument($exec, $doc);
                if ($docAnalysis) {
                    $doc->update([
                        'doc_type' => $docAnalysis['doc_type'],
                        'summary' => $docAnalysis['summary'],
                    ]);
                }
            }
        }

        $result = $ai->analyzeExecution($exec->fresh(['documents']));
        ExecService::applyAnalysis($exec, $result);
    }
}
