<?php

namespace App\Jobs;

use App\Events\MeetingStatusBroadcast;
use App\Models\Meeting;
use App\Services\LegalAiService;
use App\Support\Live;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateMeetingSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Meeting $meeting,
        public string $notes
    ) {}

    public function handle(LegalAiService $ai): void
    {
        if (filled($this->meeting->summary) && filled($this->meeting->minutes)) {
            return;
        }

        $out = $ai->meetingSummary($this->meeting->fresh(), $this->notes);
        if ($out) {
            $this->meeting->update([
                'summary' => $this->meeting->summary ?: ($out['summary'] ?? null),
                'minutes' => $this->meeting->minutes ?: ($out['minutes'] ?? null),
                'decisions' => $out['decisions'] ?? [],
                'has_summary' => true,
                'has_minutes' => true,
            ]);
            Live::push(new MeetingStatusBroadcast($this->meeting));
        }
    }
}
