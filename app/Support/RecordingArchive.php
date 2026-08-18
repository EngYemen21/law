<?php

namespace App\Support;

use App\Models\Meeting;
use App\Services\ZoomService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * تنزيل مخرجات جلسة Zoom (فيديو/صوت مضغوطَين ZIP + النصّ التفريغي) — مشترك بين
 * الاستشارة (Consult) واجتماع المكتب (Meeting). الروابط السحابية play_url صفحات مشاهدة
 * لا ملفات، فالجلب خادميّ: واجهة التسجيلات أولاً (download_url + رمز)، وإلا الرابط
 * المخزّن إن كان رابط تنزيل مباشراً.
 */
class RecordingArchive
{
    /** فيديو (mp4) أو صوت (m4a) الجلسة ملفاً مضغوطاً ZIP يُبثّ ثم يُحذف. */
    public static function zip(Model $model, string $type = 'video'): BinaryFileResponse
    {
        $ref = (string) ($model->ref ?: $model->getKey());
        $kind = $model instanceof Meeting ? 'meeting' : 'consult';
        $ext = $type === 'audio' ? 'm4a' : 'mp4';

        $source = app(ZoomService::class)->recordingDownload((string) $model->meet_id, $type);
        $url = $source['url'] ?? null;
        $token = $source['token'] ?? null;
        if ($url === null) {
            $stored = (string) ($type === 'audio' ? ($model->zoom_audio_url ?: '') : ($model->recording_url ?: ''));
            if (str_contains($stored, '/rec/download/') || str_ends_with($stored, '.'.$ext)) {
                $url = $stored;
            }
        }
        abort_if($url === null, 404, $type === 'audio'
            ? 'لا يتوفر ملف صوت قابل للتنزيل لهذه الجلسة.'
            : 'لا يتوفر ملف تسجيل قابل للتنزيل لهذه الجلسة.');

        @set_time_limit(300);
        $dir = storage_path('app/tmp-archive');
        File::ensureDirectoryExists($dir);
        $media = $dir.'/'.$ref.'-'.uniqid().'.'.$ext;
        $zipPath = $dir.'/'.($type === 'audio' ? 'audio-' : 'recording-').$ref.'-'.uniqid().'.zip';

        try {
            if ($token) {
                $url .= (str_contains($url, '?') ? '&' : '?').'access_token='.$token;
            }
            // sink يكتب مباشرة للقرص (ملفات كبيرة بلا ابتلاع ذاكرة)؛ وفي بيئة Http::fake يُتجاهل فنكتب الجسم
            $resp = Http::withOptions(['sink' => $media])->timeout(280)->get($url);
            abort_unless($resp->successful(), 404, 'تعذّر جلب الملف من سحابة Zoom.');
            clearstatcache(true, $media);
            if (! is_file($media) || filesize($media) === 0) {
                File::put($media, $resp->body());
            }
            clearstatcache(true, $media);
            abort_if(filesize($media) === 0, 404, 'الملف فارغ لدى Zoom.');

            $zip = new ZipArchive;
            abort_unless($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500, 'تعذّر إنشاء الملف المضغوط.');
            $zip->addFile($media, "{$kind}-{$ref}.{$ext}");
            $zip->close();
        } finally {
            // الملف الوسيط يُحذف دوماً (النجاح أو الفشل)؛ الـZIP يحذفه deleteFileAfterSend بعد البثّ
            @unlink($media);
        }

        return response()->download($zipPath, ($type === 'audio' ? 'audio-' : 'recording-')."{$ref}.zip", ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }

    /**
     * النصّ التفريغي للجلسة (txt): المحفوظ محلياً إن وُجد، وإلا يُجلب من سحابة Zoom
     * (واجهة التسجيلات → download_url + رمز)، يُنظَّف من ترويسة VTT، يُحفظ محلياً، ثم يُبثّ.
     */
    public static function transcript(Model $model): StreamedResponse
    {
        $ref = (string) ($model->ref ?: $model->getKey());
        $kind = $model instanceof Meeting ? 'meeting' : 'consult';

        if ($model->transcript_path && Storage::disk('local')->exists($model->transcript_path)) {
            return Storage::disk('local')->download($model->transcript_path, "transcript-{$ref}.txt");
        }

        $zoom = app(ZoomService::class);
        $details = $zoom->fetchPastMeetingDetails((string) $model->meet_id);
        $url = $details['transcript_url'] ?? null;
        $token = $details['download_token'] ?? null;
        abort_if($url === null || $token === null, 404, 'لا نصّ تفريغياً متاحاً لهذه الجلسة لدى Zoom.');

        $text = $zoom->downloadTranscript((string) $url, (string) $token);
        abort_if($text === null || trim($text) === '', 404, 'تعذّر جلب النصّ التفريغي من Zoom.');

        $path = "transcripts/{$kind}-{$ref}.txt";
        Storage::disk('local')->put($path, $text);
        $model->update(['transcript_path' => $path]);

        return Storage::disk('local')->download($path, "transcript-{$ref}.txt");
    }
}
