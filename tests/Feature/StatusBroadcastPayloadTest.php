<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\CaseStatus;
use App\Events\CaseStatusBroadcast;
use App\Events\ExecStatusBroadcast;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Support\ExecFlow;
use Tests\TestCase;

/**
 * حمولة بثّ الحالة موحّدة مع نمط التذاكر: «status» و«tone» فقط.
 *
 * كان بثّ القضية/التنفيذ يحمل حقل «next» (الجلسة القادمة/آخر إجراء) بينما مستمِع
 * الواجهة يلتقط status+tone فقط — حِمل ميّت لا يُستهلَك. هذه الاختبارات تمنع عودته.
 */
class StatusBroadcastPayloadTest extends TestCase
{
    public function test_case_status_broadcast_carries_only_status_tone_and_state_flags(): void
    {
        $case = new LegalCase;
        $case->status = 'منظورة';

        $payload = (new CaseStatusBroadcast($case))->broadcastWith();

        // والعلمان (`LegalCase::stateFlags`) تقرؤهما صفحات القضيّة الثلاث حين تتقدّم الحالة — لا حِمل ميّت
        $this->assertSame(['status', 'tone', 'isActive', 'postJudgment'], array_keys($payload));
        $this->assertTrue($payload['isActive']);
        $this->assertFalse($payload['postJudgment']);
        $this->assertArrayNotHasKey('next', $payload);
        $this->assertSame('منظورة', $payload['status']);
        $this->assertSame(CaseStatus::InCourt->tone(), $payload['tone']);
    }

    public function test_exec_status_broadcast_carries_only_status_and_tone(): void
    {
        $exec = new Execution;
        $exec->status = 'جارٍ';
        // اللون يُحسب من المرحلة الفعّالة (`Execution::tone` ← `ExecFlow::tone`) لا من عمودٍ يُكتب باليد
        $exec->stage = 3;

        $payload = (new ExecStatusBroadcast($exec))->broadcastWith();

        $this->assertSame(['status', 'tone'], array_keys($payload));
        $this->assertArrayNotHasKey('next', $payload);
        $this->assertSame('جارٍ', $payload['status']);
        $this->assertSame(ExecFlow::tone(3), $payload['tone']);
    }
}
