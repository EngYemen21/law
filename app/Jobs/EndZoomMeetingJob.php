<?php

namespace App\Jobs;

use App\Services\ZoomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * **إغلاقُ غرفة Zoom لجلسةٍ أُنهيت في النظام — في الخلفيّة، بإعادة محاولة.** (قرار المالك 2026-09-26)
 *
 * «إنهاء» لدى الطاقم يختم السجلّ، والغرفة تبقى حيّةً ما لم تُغلق: يبقى فيها الموكّل أو يعود إليها
 * من بلغه الرابط بعد أن أُعلنت الجلسة منتهية. وكان الإغلاق نداءً متزامناً داخل طلب الزرّ قبل
 * الختم أو بعده في كلّ متحكّم بنسخته — مهلةٌ ١٥ ثانية ومحاولتان يقف عليها الزرّ حين يتعثّر Zoom،
 * وفشلُه يُبتلع مرّةً واحدة بلا إعادة. مكانُه الطابور، كنظيره `DropZoomMeetingJob`.
 *
 * يُطلقه `HandleSessionEndedInSystem` **بعد التزام** انتقال الإنهاء (`EndSession` / `EndMeeting`)،
 * فلا تُغلق غرفةٌ لختمٍ تراجع. ولا يُطلق حين جاء الإنهاء من Zoom نفسه (`meeting.ended`).
 *
 * وفشلُه لا يمسّ الختم — الجلسة مختومةٌ قبله؛ بعد استنفاد المحاولات يُسجَّل بمفتاح
 * `session.zoom_room_open`: غرفةٌ قد تبقى مفتوحة لجلسةٍ منتهية، تستحقّ أن تُلتقَط.
 */
class EndZoomMeetingJob implements ShouldQueue
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
        // بيئةٌ بلا مفاتيح Zoom (التطوير والاختبارات): لا غرفة حقيقيّة تُغلق، ولا محاولةَ تُعاد
        if (! $zoom->isConfigured()) {
            return;
        }

        if ($zoom->endMeeting($this->meetId)) {
            return;
        }

        // `endMeeting` لا يرمي — فيُرمى هنا ليُعيد الطابور المحاولة على التعذّر العابر وحده
        throw new \RuntimeException("تعذّر إغلاق غرفة Zoom {$this->meetId} ({$this->ref}).");
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('session.zoom_room_open', ['ref' => $this->ref, 'meet_id' => $this->meetId, 'error' => $e?->getMessage()]);
    }
}
