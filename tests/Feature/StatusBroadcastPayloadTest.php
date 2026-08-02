<?php

namespace Tests\Feature;

use App\Events\CaseStatusBroadcast;
use App\Events\ExecStatusBroadcast;
use App\Models\Execution;
use App\Models\LegalCase;
use Tests\TestCase;

/**
 * حمولة بثّ الحالة موحّدة مع نمط التذاكر: «status» و«tone» فقط.
 *
 * كان بثّ القضية/التنفيذ يحمل حقل «next» (الجلسة القادمة/آخر إجراء) بينما مستمِع
 * الواجهة يلتقط status+tone فقط — حِمل ميّت لا يُستهلَك. هذه الاختبارات تمنع عودته.
 */
class StatusBroadcastPayloadTest extends TestCase
{
    public function test_case_status_broadcast_carries_only_status_and_tone(): void
    {
        $case = new LegalCase;
        $case->status = 'منظورة';
        $case->tone = 'b-blue';

        $payload = (new CaseStatusBroadcast($case))->broadcastWith();

        $this->assertSame(['status', 'tone'], array_keys($payload));
        $this->assertArrayNotHasKey('next', $payload);
        $this->assertSame('منظورة', $payload['status']);
        $this->assertSame('b-blue', $payload['tone']);
    }

    public function test_exec_status_broadcast_carries_only_status_and_tone(): void
    {
        $exec = new Execution;
        $exec->status = 'جارٍ';
        $exec->tone = 'b-blue';

        $payload = (new ExecStatusBroadcast($exec))->broadcastWith();

        $this->assertSame(['status', 'tone'], array_keys($payload));
        $this->assertArrayNotHasKey('next', $payload);
        $this->assertSame('جارٍ', $payload['status']);
        $this->assertSame('b-blue', $payload['tone']);
    }
}
