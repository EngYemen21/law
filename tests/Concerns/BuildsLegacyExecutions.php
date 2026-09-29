<?php

namespace Tests\Concerns;

use App\Jobs\AnalyzeExecutionJob;
use App\Models\Execution;
use App\Models\User;
use App\Support\ExecFlow;
use App\Support\ReferenceNumber;

/**
 * ملفّ تنفيذٍ في «التحليل الذكيّ» (المرحلة 1) كما تركه الطلب المباشر المحذوف.
 *
 * طلب التنفيذ صار يُفتح تذكرةً في قسم التنفيذ (قرار المالك 2026-09-29)، فلا مسار في الشيفرة
 * يُنشئ ملفّاً في المرحلة 1 بعد اليوم؛ لكنّ ملفّاتٍ قائمة ما زالت فيها وفي الدراسة (2) ويجب
 * أن تكمل رحلتها — فاختبارات التحليل والإحالة والتقاط المحامي تبدأ من هذه الحالة.
 */
trait BuildsLegacyExecutions
{
    /** @param array{sanad?: string, subject?: string, defendant?: string, amount?: int} $data */
    protected function legacyExecution(User $client, array $data = [], bool $analyze = true): Execution
    {
        $exec = Execution::create([
            'user_id' => $client->id,
            'client_code' => 'CL-'.str_pad((string) $client->id, 6, '0', STR_PAD_LEFT),
            'number' => ReferenceNumber::next(Execution::class, 'number', 'EXE'),
            'subject' => $data['subject'] ?? 'تحصيل قيمة شيك مرتجع',
            'sanad' => $data['sanad'] ?? 'شيك',
            'defendant' => $data['defendant'] ?? '',
            'amount' => (int) ($data['amount'] ?? 0),
            'notes' => '',
            'docs' => ['السند التنفيذي', 'الهوية'],
            'stage' => 1,
            'status' => ExecFlow::label(1),
            'tone' => ExecFlow::tone(1),
            'ai_done' => false,
        ]);

        // التحليل يجري كما يجري لملفّ قائم (`RetryExecutionStudies`) — والطابور sync في الاختبار
        if ($analyze) {
            AnalyzeExecutionJob::dispatch($exec);
        }

        return $exec->refresh();
    }
}
