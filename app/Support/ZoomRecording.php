<?php

namespace App\Support;

use App\Events\ConsultStatusBroadcast;
use App\Events\MeetingStatusBroadcast;
use App\Models\Consult;
use App\Models\Meeting;
use App\Services\ZoomService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * مخرجات التسجيل من Zoom (recording.completed): حفظ رابط التسجيل السحابي + تنزيل النصّ
 * التفريغي وحفظه محلياً (ليقرأه الذكاء الاصطناعي لاحقاً). مشترك بين الاستشارة والاجتماع.
 */
class ZoomRecording
{
    /**
     * @param  array<int, array<string, mixed>>  $files  recording_files من الحدث
     */
    public static function pull(Model $model, array $files, string $token, ZoomService $zoom): bool
    {
        if (! empty($model->recording_url)) {
            return false; // جُلب سابقاً — idempotent
        }

        $ref = (string) ($model->ref ?: $model->getKey());
        $kind = $model instanceof Meeting ? 'meeting' : 'consult';

        // رابط تسجيل الفيديو السحابي
        $mp4 = self::firstOfType($files, 'MP4');
        $recordingUrl = $mp4['play_url'] ?? $mp4['download_url'] ?? null;

        // رابط تسجيل الصوت فقط
        $m4a = self::firstOfType($files, 'M4A');
        $audioUrl = $m4a['play_url'] ?? $m4a['download_url'] ?? null;

        // النصّ التفريغي → تنزيل وحفظ محليّ
        $transcriptPath = null;
        $transcriptFile = self::firstOfType($files, 'TRANSCRIPT');
        $downloadUrl = $transcriptFile['download_url'] ?? null;
        if ($downloadUrl && ($text = $zoom->downloadTranscript($downloadUrl, $token)) !== null) {
            $transcriptPath = "transcripts/{$kind}-{$ref}.txt";
            Storage::disk('local')->put($transcriptPath, $text);
        }

        if ($recordingUrl === null && $transcriptPath === null && $audioUrl === null) {
            return false;
        }

        $model->update(array_filter([
            'recording_url' => $recordingUrl,
            'zoom_audio_url' => $audioUrl,
            'transcript_path' => $transcriptPath,
        ]));
        self::broadcast($model);

        return true;
    }

    /** @param  array<int, array<string, mixed>>  $files */
    private static function firstOfType(array $files, string $type): ?array
    {
        foreach ($files as $f) {
            if (($f['file_type'] ?? '') === $type) {
                return $f;
            }
        }

        return null;
    }

    private static function broadcast(Model $model): void
    {
        if ($model instanceof Consult) {
            Live::push(new ConsultStatusBroadcast($model));
        } elseif ($model instanceof Meeting) {
            Live::push(new MeetingStatusBroadcast($model));
        }
    }
}
