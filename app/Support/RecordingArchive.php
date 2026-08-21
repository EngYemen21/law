<?php

namespace App\Support;

use App\Jobs\BuildRecordingArchive;
use App\Models\Meeting;
use App\Services\ZoomService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
    /** مسار الأرشيف المحلّي المتوقَّع لهذه الجلسة ونوع الوسيط. */
    public static function localPath(Model $model, string $type): string
    {
        $kind = $model instanceof Meeting ? 'meeting' : 'consult';
        $ref = (string) ($model->ref ?: $model->getKey());

        return "recordings/{$kind}-{$ref}-{$type}.zip";
    }

    /** هل الأرشيف جاهز محلياً (فيُنزَّل فوراً بلا انتظار سحابة Zoom)؟ */
    public static function isReady(Model $model, string $type): bool
    {
        return Storage::disk('local')->exists(self::localPath($model, $type));
    }

    /** اسم الملف المعروض للمستخدم. */
    public static function fileName(Model $model, string $type): string
    {
        $ref = (string) ($model->ref ?: $model->getKey());

        return ($type === 'audio' ? 'audio-' : 'recording-')."{$ref}.zip";
    }

    /**
     * تنزيل الأرشيف: المحفوظ محلياً فوراً، وإلا يُجدوَل بناؤه في الطابور ويُبلَّغ المستخدم.
     *
     * لماذا: الجلب من سحابة Zoom يستغرق دقائق (ملفات فيديو كبيرة) بينما مهلة nginx
     * الافتراضية 60ث ⇒ 504 حتمي لأي تسجيل متوسط الحجم. البناء انتقل للطابور،
     * والزرّ يبقى زرّ تنزيل عاديّاً لأن المجدول يبني الأرشيف سلفاً بعد انتهاء الجلسة.
     */
    public static function download(Model $model, string $type = 'video'): StreamedResponse|RedirectResponse
    {
        $path = self::localPath($model, $type);

        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->download($path, self::fileName($model, $type));
        }

        BuildRecordingArchive::for($model, $type);

        return back()->with('flash', 'جارٍ تحضير ملف الجلسة — سيصلك إشعار فور جهوزيته ثم ينزل فوراً.');
    }

    /**
     * يبني الأرشيف ويحفظه محلياً — يُستدعى من الطابور/المجدول لا من الويب.
     * يعيد المسار المحلي، أو null إن تعذّر (لا رابط لدى Zoom / ملف فارغ).
     */
    public static function build(Model $model, string $type = 'video'): ?string
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
        if ($url === null) {
            return null; // لا رابط لدى Zoom بعد
        }

        WebTimeLimit::raise(300); // بلا أثر في الطابور (runningInConsole) — للاحتياط لو نُودي من الويب
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
            if (! $resp->successful()) {
                return null;
            }
            clearstatcache(true, $media);
            if (! is_file($media) || filesize($media) === 0) {
                File::put($media, $resp->body());
            }
            clearstatcache(true, $media);
            if (filesize($media) === 0) {
                return null;
            }

            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return null;
            }
            $zip->addFile($media, "{$kind}-{$ref}.{$ext}");
            $zip->close();

            // يُنقل إلى التخزين المحلّي الدائم ليُخدَم فوراً في كل تنزيل لاحق.
            // بالتدفّق لا بالقراءة الكاملة: أرشيف جلسة 45 دقيقة يبلغ مئات الميغابايت،
            // وFile::get كان يحمّله كلّه في الذاكرة فتسقط المهمة بـAllowed memory size exhausted.
            $path = self::localPath($model, $type);
            $stream = fopen($zipPath, 'rb');
            if ($stream === false) {
                return null;
            }
            try {
                Storage::disk('local')->writeStream($path, $stream);
            } finally {
                fclose($stream);
            }

            return $path;
        } finally {
            @unlink($media);
            @unlink($zipPath);
        }
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
