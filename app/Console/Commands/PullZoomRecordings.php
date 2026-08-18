<?php

namespace App\Console\Commands;

use App\Models\Consult;
use App\Models\Meeting;
use App\Services\ZoomService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * استكمال بيانات تسجيلات Zoom للجلسات المنعقدة — شبكة أمان للويبهوك:
 * جلسة انعقدت والويبهوك لم يصل (بيئة محلية بلا نفق، انقطاع، سرّ غير مضبوط) تبقى بلا
 * روابط تسجيل/صوت ولا نصّ تفريغي فتتعطّل أزرار التنزيل. يستطلع سحابة Zoom ويملأ الناقص.
 */
class PullZoomRecordings extends Command
{
    protected $signature = 'zoom:pull-recordings';

    protected $description = 'جلب روابط التسجيل/الصوت والنص التفريغي الناقصة للجلسات المنعقدة من سحابة Zoom';

    public function handle(ZoomService $zoom): int
    {
        if (! $zoom->isConfigured()) {
            $this->warn('مفاتيح Zoom S2S غير مهيأة — لا شيء يُجلب.');

            return self::SUCCESS;
        }

        $targets = Meeting::where('status', 'منتهٍ')->whereNotNull('meet_id')
            ->where(fn ($q) => $q->whereNull('recording_url')->orWhereNull('zoom_audio_url')->orWhereNull('transcript_path'))
            ->get()
            ->concat(
                Consult::where('session', 'منتهية')->whereNotNull('meet_id')
                    ->where(fn ($q) => $q->whereNull('recording_url')->orWhereNull('zoom_audio_url')->orWhereNull('transcript_path'))
                    ->get()
            );

        $filled = 0;
        foreach ($targets as $model) {
            if ($this->fill($zoom, $model)) {
                $filled++;
            }
        }

        $this->info("فُحص {$targets->count()} جلسة، واستُكملت بيانات {$filled} منها.");

        return self::SUCCESS;
    }

    /** يملأ الحقول الناقصة فقط (لا يدهس الموجود) — يعيد true إن أُضيف شيء. */
    private function fill(ZoomService $zoom, Model $model): bool
    {
        $details = $zoom->fetchPastMeetingDetails((string) $model->meet_id);
        if ($details === null) {
            return false;
        }

        $updates = array_filter([
            'zoom_uuid' => $model->zoom_uuid ? null : ($details['uuid'] ?? null),
            'zoom_share_url' => $model->zoom_share_url ? null : ($details['share_url'] ?? null),
            'recording_url' => $model->recording_url ? null : ($details['recording_url'] ?? null),
            'zoom_audio_url' => $model->zoom_audio_url ? null : ($details['audio_url'] ?? null),
            'join_time' => $model->join_time ? null : ($details['starts_at'] ?? null),
            'leave_time' => $model->leave_time ? null : ($details['ends_at'] ?? null),
            'duration_sec' => $model->duration_sec !== null ? null : ($details['duration_sec'] ?? null),
        ]);

        // النصّ التفريغي: تنزيل وتنظيف وحفظ محلي (كما يفعل مسار الويبهوك)
        if (! $model->transcript_path && ! empty($details['transcript_url']) && ! empty($details['download_token'])) {
            $text = $zoom->downloadTranscript((string) $details['transcript_url'], (string) $details['download_token']);
            if ($text !== null && trim($text) !== '') {
                $kind = $model instanceof Meeting ? 'meeting' : 'consult';
                $ref = (string) ($model->ref ?: $model->getKey());
                $path = "transcripts/{$kind}-{$ref}.txt";
                Storage::disk('local')->put($path, $text);
                $updates['transcript_path'] = $path;
            }
        }

        if ($updates === []) {
            return false;
        }

        $model->update($updates);
        $this->line('  ✓ '.($model->ref ?: $model->getKey()).': '.implode('، ', array_keys($updates)));

        return true;
    }
}
