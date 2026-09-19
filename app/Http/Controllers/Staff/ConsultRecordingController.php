<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Concerns\ScopedToLawyer;
use App\Http\Controllers\Controller;
use App\Models\Consult;
use App\Support\RecordingArchive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * **مخرجات جلسة الاستشارة عبر الخادم — للطاقم وحده، ولا رابطَ خارجيّ.**
 *
 * لماذا: كانت صفحة الاستشارة تفتح تسجيل Zoom وصوته ورابط مشاركته في نافذةٍ خارج النظام،
 * ومساراتُ التنزيل الداخليّة للإدارة وحدها (أرشيف الاستشارات) — فلم يكن للموظّف والمحامي
 * غيرُ الرابط الخارجيّ. متحكّمٌ واحد لأدوار المكتب الثلاثة بمسارٍ لكلّ دور:
 *   - المحامي: الاستشارات المسندة إليه فقط (`ScopedToLawyer`).
 *   - الموظّف: بصلاحيّة «استقبال الاستشارات» (وسيط المسار).
 *   - الإدارة: بصلاحيّة «أرشيف الاستشارات» (وسيط المسار).
 * والعميل لا يرى تسجيل استشارته (قرار المالك 2026-09-15) — لا مسارَ له هنا.
 */
class ConsultRecordingController extends Controller
{
    use ScopedToLawyer;

    /** تنزيل فيديو الجلسة (MP4) — المحفوظ فوراً، وإلا يُحضَّر ويُبلَّغ الطالب. */
    public function video(Request $request, Consult $consult): StreamedResponse|RedirectResponse
    {
        $this->guard($request, $consult);

        return RecordingArchive::download($consult, 'video');
    }

    /** تنزيل صوت الجلسة (M4A). */
    public function audio(Request $request, Consult $consult): StreamedResponse|RedirectResponse
    {
        $this->guard($request, $consult);

        return RecordingArchive::download($consult, 'audio');
    }

    /** النصّ التفريغي (txt) — المحفوظ، وإلا يُجلب من Zoom عبر الخادم ويُحفظ. */
    public function transcript(Request $request, Consult $consult): StreamedResponse
    {
        $this->guard($request, $consult);

        return RecordingArchive::transcript($consult);
    }

    /** تشغيل الفيديو أو الصوت داخل الصفحة. */
    public function stream(Request $request, Consult $consult, string $type): BinaryFileResponse
    {
        $this->guard($request, $consult);

        return RecordingArchive::stream($consult, $type);
    }

    /** الإسناد للمحامي، والصلاحيّة للموظّف، ولا مخرجات لجلسةٍ لم تنعقد. */
    private function guard(Request $request, Consult $consult): void
    {
        RecordingArchive::guardViewer($request->user());

        if ($request->user()->isLawyer()) {
            $this->guardAssigned($consult);
        }

        abort_unless($consult->session === 'منتهية', 404, 'لا تسجيل لهذه الاستشارة — جلستها لم تنعقد.');
    }
}
