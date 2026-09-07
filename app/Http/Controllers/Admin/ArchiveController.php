<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consult;
use App\Models\Ticket;
use App\Support\RecordingArchive;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * أرشيف الاستشارات — الاستشارات المنتهية الحقيقية (تسجيلاتها وملخصاتها) بدل مصفوفة ARCHIVE،
 * مع تنزيل مخرجات الجلسة عبر الخادم: فيديو/صوت مضغوطَين (ZIP) والنصّ التفريغي (txt).
 */
class ArchiveController extends Controller
{
    public function index(): Response
    {
        // «الجلسة منتهية» هي المعيار — الحالة قد تتحوّل («محولة إلى قضية») فتسقط جلسات منعقدة من الأرشيف
        $rows = Consult::with('user')->where('session', 'منتهية')->latest('id')->get()
            ->map(function (Consult $c) {
                $hasZoom = $c->meet_id || $c->recording_url || $c->zoom_share_url;

                return [
                    'id' => $c->id,
                    'ref' => $c->ref,
                    'channel' => $c->channel,
                    'ctype' => 'استشارة '.$c->channel,
                    'client' => Ticket::maskClient($c->user?->name ?? ''),
                    'lawyer' => $c->lawyer ?: '—',
                    'specialty' => $c->specialty ?: ($c->type ?: 'عام'),
                    'subject' => $c->subject,
                    'date' => $c->whenLabel(),
                    'dur' => $c->duration_label ?: '—',
                    'total' => $c->total,
                    'status' => $c->status,
                    'summary' => $c->summary,
                    'hasSummary' => ! empty($c->summary),
                    'decisions' => $c->decisions ?? [],
                    // مخرجات Zoom السحابية
                    'recording' => $c->recording_url ?: $c->zoom_share_url,
                    'zip' => $hasZoom ? route('admin.consults.recording', $c, absolute: false) : null,
                    'audioZip' => ($c->meet_id || $c->zoom_audio_url) ? route('admin.consults.audio', $c, absolute: false) : null,
                    'transcript' => ($c->transcript_path || $c->meet_id) ? route('admin.consults.transcript', $c, absolute: false) : null,
                    /*
                     * **جاهزٌ يعني موجودٌ على القرص.**
                     *
                     * كانت الروابط تُرسَل لمجرّد وجود `meet_id`، والزرّ `<a href>` عاديّ.
                     * فإن لم يكن الملفّ مبنيّاً ردّ `RecordingArchive::download` بـ`back()`
                     * — فتُعيد النقرةُ تحميلَ الصفحة **ولا يُنزَّل شيء**، والمستخدم يعيد
                     * النقر ظنّاً أنّ الأولى ضاعت فتُجدوَل مهمّةُ بناءٍ في كلّ مرّة.
                     *
                     * `RecordingArchive::isReady` كانت قائمةً ولا يستدعيها الأرشيف.
                     */
                    'videoReady' => $hasZoom && RecordingArchive::isReady($c, 'video'),
                    'audioReady' => ($c->meet_id || $c->zoom_audio_url) && RecordingArchive::isReady($c, 'audio'),
                    'transcriptReady' => (bool) $c->transcript_path,
                ];
            });

        return Inertia::render('admin/archive', ['rows' => $rows]);
    }

    // فيديو جلسة الاستشارة مضغوطاً ZIP (جلب خادمي من سحابة Zoom)
    public function recording(Consult $consult): StreamedResponse|RedirectResponse
    {
        abort_unless($consult->session === 'منتهية', 404, 'لا تسجيل لهذه الاستشارة — جلستها لم تنعقد.');

        return RecordingArchive::download($consult, 'video');
    }

    // صوت الجلسة (M4A) مضغوطاً ZIP
    public function audio(Consult $consult): StreamedResponse|RedirectResponse
    {
        abort_unless($consult->session === 'منتهية', 404, 'لا تسجيل لهذه الاستشارة — جلستها لم تنعقد.');

        return RecordingArchive::download($consult, 'audio');
    }

    // النصّ التفريغي للجلسة (txt) — المحلي إن وُجد وإلا يُجلب من السحابة ويُحفظ
    public function transcript(Consult $consult): StreamedResponse
    {
        abort_unless($consult->session === 'منتهية', 404, 'لا نصّ لهذه الاستشارة — جلستها لم تنعقد.');

        return RecordingArchive::transcript($consult);
    }
}
