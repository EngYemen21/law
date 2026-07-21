<?php

namespace App\Jobs;

use App\Models\Consult;
use App\Models\Meeting;
use App\Services\ZoomService;
use App\Support\ConsultSummary;
use App\Support\MeetingSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * معالجة ملخّص Zoom خارج مسار الويبهوك: تحليل الحمولة + حفظ + بثّ + استخراج المهام (نداء LLM).
 * الويبهوك يُرسِلها ويردّ 200 فوراً — Zoom يُعطّل النقاط البطيئة، والتلخيص يتضمّن نداء AI ثقيلاً.
 * نظير ProcessZoomRecordingJob. idempotent عبر zoom_summary_at داخل pull().
 *
 * @property array<string, mixed> $payload محتوى payload.object من حدث meeting.summary_completed
 */
class ProcessZoomSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> تراجع تصاعدي بين المحاولات (ثوانٍ) */
    public array $backoff = [60, 300];

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Model $model,
        public array $payload,
    ) {}

    public function handle(ZoomService $zoom): void
    {
        if ($this->model instanceof Consult) {
            ConsultSummary::pull($this->model, $zoom, $this->payload);
        } elseif ($this->model instanceof Meeting) {
            MeetingSummary::pull($this->model, $zoom, $this->payload);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('ProcessZoomSummaryJob failed for '.$this->model::class.'#'.$this->model->getKey().': '.$e->getMessage());
    }
}
