<?php

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * كتابة قيد التدقيق عبر الطابور — سياق الطلب (المستخدم/IP/المتصفح/الوقت) التُقط
 * مسبقاً في الحمولة لحظة الحدث، فالعامل يكتب فقط ولا يعتمد على طلب HTTP.
 * الفشل صامت: السجل توثيق لاحق ولا يجوز أن يُسقط العملية أو يُعاد بلا نهاية.
 */
class RecordAuditLogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        try {
            AuditLog::create($this->payload);
        } catch (\Throwable $e) {
            Log::warning('[Audit] تعذّرت كتابة قيد التدقيق من الطابور: '.$e->getMessage());
        }
    }
}
