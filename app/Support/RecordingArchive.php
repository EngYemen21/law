<?php

namespace App\Support;

use App\Jobs\BuildRecordingArchive;
use App\Models\Meeting;
use App\Services\ZoomService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تنزيل مخرجات جلسة Zoom (فيديو MP4 / صوت M4A بصيغتهما الأصلية + النصّ التفريغي) — مشترك بين
 * الاستشارة (Consult) واجتماع المكتب (Meeting). الروابط السحابية play_url صفحات مشاهدة
 * لا ملفات، فالجلب خادميّ: واجهة التسجيلات أولاً (download_url + رمز)، وإلا الرابط
 * المخزّن إن كان رابط تنزيل مباشراً.
 */
class RecordingArchive
{
    /**
     * مسار الملف المحلّي المتوقَّع لهذه الجلسة ونوع الوسيط.
     *
     * بصيغة الوسيط الأصلية (mp4/m4a) لا ZIP: ضغط الملف أُلغي بقرار صاحب المنتج
     * (2026-08-26) — كان يعطّل التنزيل ويضيف خطوة فكّ بلا فائدة (الفيديو مضغوط أصلاً).
     */
    public static function localPath(Model $model, string $type): string
    {
        $kind = $model instanceof Meeting ? 'meeting' : 'consult';
        $ref = (string) ($model->ref ?: $model->getKey());
        $ext = $type === 'audio' ? 'm4a' : 'mp4';

        return "recordings/{$kind}-{$ref}-{$type}.{$ext}";
    }

    /** مسار أرشيف ZIP القديم — يُخدَم إن سبق بناؤه كي لا يُعاد جلب الملف من Zoom. */
    public static function legacyZipPath(Model $model, string $type): string
    {
        $kind = $model instanceof Meeting ? 'meeting' : 'consult';
        $ref = (string) ($model->ref ?: $model->getKey());

        return "recordings/{$kind}-{$ref}-{$type}.zip";
    }

    /** هل الملف جاهز محلياً (فيُنزَّل فوراً بلا انتظار سحابة Zoom)؟ */
    public static function isReady(Model $model, string $type): bool
    {
        return Storage::disk('local')->exists(self::localPath($model, $type))
            || Storage::disk('local')->exists(self::legacyZipPath($model, $type));
    }

    /** اسم الملف المعروض للمستخدم. */
    public static function fileName(Model $model, string $type): string
    {
        $ref = (string) ($model->ref ?: $model->getKey());
        $ext = $type === 'audio' ? 'm4a' : 'mp4';

        return ($type === 'audio' ? 'audio-' : 'recording-')."{$ref}.{$ext}";
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

        // أرشيف ZIP سبق بناؤه قبل إلغاء الضغط — يُخدَم كما هو بدل إعادة جلب الملف من Zoom
        $legacy = self::legacyZipPath($model, $type);
        if (Storage::disk('local')->exists($legacy)) {
            $ref = (string) ($model->ref ?: $model->getKey());

            return Storage::disk('local')->download($legacy, ($type === 'audio' ? 'audio-' : 'recording-')."{$ref}.zip");
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

            // حارس المحتوى: Zoom يعيد صفحة HTML/JSON بـ200 حين يغيب التفويض — حفظها باسم
            // mp4 يُنتج ملفاً «ينزل ولا يعمل». الرفض هنا يُبقي المسار على «جارٍ التحضير»
            // بدل تسليم ملف معطوب (لا فحص إيجابي لترويسة الوسيط — الاختبارات تحقن أجساماً نصية)
            $head = ltrim((string) file_get_contents($media, false, null, 0, 256));
            if (str_starts_with($head, '<') || str_starts_with($head, '{')) {
                return null;
            }

            // خطوة ضغط ZIP أُلغيت (قرار صاحب المنتج 2026-08-26): كانت تعطّل التنزيل وتضيف
            // فكّ ضغط بلا فائدة — الوسيط يُنقل بصيغته الأصلية مباشرة إلى التخزين الدائم.
            // بالتدفّق لا بالقراءة الكاملة: جلسة 45 دقيقة تبلغ مئات الميغابايت،
            // وFile::get كان يحمّلها كلّها في الذاكرة فتسقط المهمة بـAllowed memory size exhausted.
            $path = self::localPath($model, $type);
            $stream = fopen($media, 'rb');
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

        $legacyStored = null;
        if ($model->transcript_path && Storage::disk('local')->exists($model->transcript_path)) {
            $stored = (string) Storage::disk('local')->get($model->transcript_path);
            // ملفّ بصيغة VTT (يحمل التوقيت والمتحدث) يُبثّ كما هو؛ القديم المنظَّف من التوقيتات
            // يُعاد جلبه خاماً — عرض «الكلام مع الوقت ومن المتحدث» يستحيل بلا أسطر التوقيت
            if (str_contains($stored, '-->')) {
                return Storage::disk('local')->download($model->transcript_path, "transcript-{$ref}.txt");
            }
            // يُحتفظ به احتياطاً: إن تعذّرت إعادة الجلب من Zoom فالنصّ القديم خير من 404
            $legacyStored = $model->transcript_path;
        }

        // الجلسة المنقطعة = عدّة انعقادات بعدّة نصوص لدى Zoom — تُجلب كلّها وتُدمج زمنياً
        // خاماً بلا أي تعديل (uuid الانعقاد يُرمَّز مرّتين كما تشترط واجهة Zoom)
        $zoom = app(ZoomService::class);
        $instances = $zoom->meetingInstances((string) $model->meet_id);
        $sources = $instances !== []
            ? array_map(fn ($i) => ['id' => urlencode(urlencode($i['uuid'])), 'start' => $i['start_time']], $instances)
            : [['id' => (string) $model->meet_id, 'start' => '']];

        $parts = [];
        foreach ($sources as $src) {
            $details = $zoom->fetchPastMeetingDetails($src['id']);
            $url = $details['transcript_url'] ?? null;
            $token = $details['download_token'] ?? null;
            if ($url === null || $token === null) {
                continue;
            }
            $text = $zoom->downloadTranscript((string) $url, (string) $token, clean: false);
            if ($text !== null && trim($text) !== '') {
                $parts[] = ['start' => $src['start'], 'text' => trim($text)];
            }
        }
        if ($parts === [] && $legacyStored !== null) {
            return Storage::disk('local')->download($legacyStored, "transcript-{$ref}.txt");
        }
        abort_if($parts === [], 404, 'لا نصّ تفريغياً متاحاً لهذه الجلسة لدى Zoom.');

        $merged = '';
        foreach ($parts as $n => $p) {
            if (count($parts) > 1) {
                // فاصل جزء كوسم VTT صالح — يظهر في نافذة العرض كسطر «— الجزء N —» بوقته المحلي
                $when = $p['start'] !== ''
                    ? Carbon::parse($p['start'])->timezone(config('app.timezone'))->format('H:i')
                    : '';
                $merged .= ($merged !== '' ? "\n\n" : '')
                    ."00:00:00.000 --> 00:00:00.001\n— الجزء ".($n + 1).($when !== '' ? " ({$when})" : '')." —\n\n";
            }
            $merged .= $p['text'];
        }

        $path = "transcripts/{$kind}-{$ref}.txt";
        Storage::disk('local')->put($path, $merged);
        $model->update(['transcript_path' => $path]);

        return Storage::disk('local')->download($path, "transcript-{$ref}.txt");
    }
}
