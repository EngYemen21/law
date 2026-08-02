<?php

namespace App\Jobs;

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
        $exec = $this->execution->fresh();
        // idempotent: لا نعيد التحليل بعد اكتماله أو بعد تجاوز مرحلة التحليل
        if ($exec === null || $exec->ai_done || (int) $exec->stage > 1) {
            return;
        }

        $result = $ai->analyzeExecution($exec);
        ExecService::applyAnalysis($exec, $result);
    }
}
