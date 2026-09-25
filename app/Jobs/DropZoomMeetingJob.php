<?php

namespace App\Jobs;

use App\Services\ZoomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * **حذفُ اجتماع Zoom لموعدٍ أُلغي — في الخلفيّة، بإعادة محاولة.**
 *
 * كان الحذف يقع داخل طلب المستخدم نفسه: مهلةٌ ١٥ ثانية ومحاولتان، فيقف زرّ «إعادة الجدولة»
 * حتى ٤٥ ثانية حين يتعثّر Zoom — رُصد حيّاً (2026-09-25). والحذف لا يغيّر ما يراه المستخدم:
 * الموعد أُلغي في قاعدة البيانات قبله. فمكانُه الطابور.
 *
 * وفشله لا يُبتلع: بعد استنفاد المحاولات يُسجَّل بمفتاح `booking.zoom_orphan` — اجتماعٌ يتيم
 * على الحساب، تسجيله السحابيّ مفعَّل، يستحقّ أن يُلتقَط.
 */
class DropZoomMeetingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function __construct(
        public string $meetId,
        public string $ref,
    ) {}

    public function handle(ZoomService $zoom): void
    {
        // بيئةٌ بلا مفاتيح Zoom (التطوير والاختبارات): لا اجتماع حقيقيّ يُحذف، ولا محاولةَ تُعاد
        if (! $zoom->isConfigured()) {
            return;
        }

        if ($zoom->deleteMeeting($this->meetId)) {
            return;
        }

        // `deleteMeeting` لا يرمي — فيُرمى هنا ليُعيد الطابور المحاولة
        throw new \RuntimeException("تعذّر حذف اجتماع Zoom {$this->meetId} ({$this->ref}).");
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('booking.zoom_orphan', ['ref' => $this->ref, 'meet_id' => $this->meetId, 'error' => $e?->getMessage()]);
    }
}
