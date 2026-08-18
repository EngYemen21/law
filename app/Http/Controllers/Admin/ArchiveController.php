<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consult;
use App\Models\Ticket;
use App\Support\RecordingArchive;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
                $hasZoom = $c->meet_id || $c->recording_url;

                return [
                    'ref' => $c->ref,
                    'ctype' => 'استشارة '.$c->channel,
                    'client' => Ticket::maskClient($c->user?->name ?? ''),
                    'date' => $c->whenLabel(),
                    'dur' => $c->duration_label ?: '—',
                    // رابط التسجيل الفعلي — كان meet_link (رابط الانضمام الميّت بعد الجلسة) يُعرض «تسجيلاً»
                    'recording' => $c->recording_url ?: $c->zoom_share_url,
                    // تنزيلات خادمية (تحتاج معرّف اجتماع Zoom أو رابطاً مخزّناً)
                    'zip' => $hasZoom ? route('admin.consults.recording', $c, absolute: false) : null,
                    'audioZip' => ($c->meet_id || $c->zoom_audio_url) ? route('admin.consults.audio', $c, absolute: false) : null,
                    'transcript' => ($c->transcript_path || $c->meet_id) ? route('admin.consults.transcript', $c, absolute: false) : null,
                    'hasSummary' => ! empty($c->summary),
                ];
            });

        return Inertia::render('admin/archive', ['rows' => $rows]);
    }

    // فيديو جلسة الاستشارة مضغوطاً ZIP (جلب خادمي من سحابة Zoom)
    public function recording(Consult $consult): BinaryFileResponse
    {
        abort_unless($consult->session === 'منتهية', 404, 'لا تسجيل لهذه الاستشارة — جلستها لم تنعقد.');

        return RecordingArchive::zip($consult, 'video');
    }

    // صوت الجلسة (M4A) مضغوطاً ZIP
    public function audio(Consult $consult): BinaryFileResponse
    {
        abort_unless($consult->session === 'منتهية', 404, 'لا تسجيل لهذه الاستشارة — جلستها لم تنعقد.');

        return RecordingArchive::zip($consult, 'audio');
    }

    // النصّ التفريغي للجلسة (txt) — المحلي إن وُجد وإلا يُجلب من السحابة ويُحفظ
    public function transcript(Consult $consult): StreamedResponse
    {
        abort_unless($consult->session === 'منتهية', 404, 'لا نصّ لهذه الاستشارة — جلستها لم تنعقد.');

        return RecordingArchive::transcript($consult);
    }
}
