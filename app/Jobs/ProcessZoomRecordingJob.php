<?php

namespace App\Jobs;

use App\Services\ZoomService;
use App\Support\ZoomRecording;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessZoomRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> تراجع تصاعدي بين المحاولات (ثوانٍ) */
    public array $backoff = [60, 300];

    public int $timeout = 120;

    /**
     * @param  array<int, array<string, mixed>>  $files
     */
    public function __construct(
        public Model $model,
        public array $files,
        public string $token
    ) {}

    /**
     * حدّ الإعادة أقصر من عمر رمز التنزيل من Zoom (~24س) كي لا تُعاد المحاولة برمز منتهٍ.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(ZoomService $zoom): void
    {
        ZoomRecording::pull($this->model, $this->files, $this->token, $zoom);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('ProcessZoomRecordingJob failed for '.$this->model::class.'#'.$this->model->getKey().': '.$e->getMessage());
    }
}
