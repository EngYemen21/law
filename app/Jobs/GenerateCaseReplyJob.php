<?php

namespace App\Jobs;

use App\Models\LegalCase;
use App\Services\LegalAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateCaseReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public LegalCase $case,
        public string $body
    ) {}

    public function handle(LegalAiService $ai): void
    {
        if (in_array($this->case->fresh()->status, ['مغلقة', 'مؤرشفة'], true)) {
            return;
        }

        $aiText = $ai->caseReply($this->case, $this->body)
            ?? 'تم استلام رسالتك بخصوص القضية، وسيوافيك المختص بالرد في أقرب وقت.';

        $this->case->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => LegalAiService::AGENT_ROLE,
            'body' => nl2br(e($aiText)),
            'time_label' => $this->clock(),
        ]);
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
