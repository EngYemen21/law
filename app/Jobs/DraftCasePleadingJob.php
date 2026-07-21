<?php

namespace App\Jobs;

use App\Models\LegalCase;
use App\Services\LegalAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DraftCasePleadingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public LegalCase $case
    ) {}

    public function handle(LegalAiService $ai): void
    {
        $draft = $ai->draftPleading($this->case);

        $this->case->messages()->create([
            'who' => 'ai',
            'name' => 'المساعد القانوني',
            'role' => 'مسودة اللائحة',
            'body' => '<div class="draft" style="white-space:pre-line">'.e($draft).'</div>',
            'time_label' => $this->clock(),
        ]);
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
