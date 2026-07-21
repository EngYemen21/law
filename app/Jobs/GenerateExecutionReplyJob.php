<?php

namespace App\Jobs;

use App\Models\Execution;
use App\Services\LegalAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateExecutionReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Execution $execution,
        public string $body
    ) {}

    public function handle(LegalAiService $ai): void
    {
        if (in_array($this->execution->fresh()->status, ['مكتمل', 'مغلق'], true)) {
            return;
        }

        $aiText = $ai->execReply($this->execution, $this->body)
            ?? 'تم استلام رسالتك بخصوص طلب التنفيذ، وسيوافيك قسم التنفيذ بالمستجدات في أقرب وقت.';

        $this->execution->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => 'التنفيذ',
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
