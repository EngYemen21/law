<?php

namespace App\Http\Controllers;

use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Consult\EndSession;
use App\Domain\Journey\Transitions\Consult\ZoomSessionStarted;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Events\MeetingStatusBroadcast;
use App\Jobs\FinalizeConsultJob;
use App\Jobs\GenerateMeetingSummaryJob;
use App\Jobs\ProcessZoomRecordingJob;
use App\Jobs\ProcessZoomSummaryJob;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\ConsultSessionOutcome;
use App\Support\Live;
use App\Support\Notify;
use App\Support\ZoomWebhook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * مستقبِل أحداث Zoom (Webhooks) — يحدّث حالة الجلسة والملخّص والتسجيل/النصّ والمدّة لحظياً
 * من Zoom لكلٍّ من الاستشارات واجتماعات المكتب. محميّ بتوقيع HMAC.
 */
class ZoomWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        abort_unless(ZoomWebhook::secret() !== null, 503, 'Zoom webhook غير مُهيّأ.');

        $timestamp = (string) $request->header('x-zm-request-timestamp', '');

        abort_unless(
            ZoomWebhook::verify($timestamp, $request->getContent(), (string) $request->header('x-zm-signature', '')),
            403,
            'توقيع Zoom غير صالح.',
        );

        // منع إعادة الإرسال: نرفض الأختام القديمة (خارج ±5د)
        abort_unless(ZoomWebhook::fresh($timestamp), 403, 'ختم Zoom الزمني خارج النطاق.');

        $event = (string) $request->input('event');

        if ($event === 'endpoint.url_validation') {
            return response()->json(ZoomWebhook::validationResponse((string) $request->input('payload.plainToken')));
        }

        // توجيه الحدث للكيان المطابق بـ meet_id (استشارة أولاً ثمّ اجتماع مكتب).
        // تباين Zoom: معظم الأحداث ترسل object.id، لكن meeting.summary_completed ترسل object.meeting_id.
        $meetingId = (string) ($request->input('payload.object.id') ?: $request->input('payload.object.meeting_id', ''));
        if ($meetingId !== '') {
            if ($consult = Consult::where('meet_id', $meetingId)->first()) {
                $this->routeConsult($event, $consult, $request);
            } elseif ($meeting = Meeting::where('meet_id', $meetingId)->first()) {
                $this->routeMeeting($event, $meeting, $request);
            }
        }

        return response()->json(['ok' => true]);
    }

    private function routeConsult(string $event, Consult $consult, Request $request): void
    {
        match ($event) {
            'meeting.started' => $this->startConsultSession($consult),
            'meeting.ended' => $this->endConsult($consult),
            'meeting.summary_completed' => ProcessZoomSummaryJob::dispatch($consult, (array) $request->input('payload.object')),
            'recording.completed' => $this->recording($consult, $request),
            'meeting.participant_joined' => $this->participantJoined($consult, $request),
            'meeting.participant_left' => $this->participantLeft($consult, $request),
            default => null,
        };
    }

    private function routeMeeting(string $event, Meeting $meeting, Request $request): void
    {
        match ($event) {
            'meeting.started' => $this->setMeetingStatus($meeting, 'جارٍ'),
            'meeting.ended' => $this->endMeeting($meeting),
            'meeting.summary_completed' => ProcessZoomSummaryJob::dispatch($meeting, (array) $request->input('payload.object')),
            'recording.completed' => $this->recording($meeting, $request),
            'meeting.participant_joined' => $this->participantJoined($meeting, $request),
            'meeting.participant_left' => $this->participantLeft($meeting, $request),
            default => null,
        };
    }

    // التسجيل/النصّ ثقيلان نسبياً — يُنزَّلان بعد إرسال الاستجابة (الـwebhook يردّ 200 فوراً)
    private function recording(Model $model, Request $request): void
    {
        $files = (array) $request->input('payload.object.recording_files', []);
        $token = (string) ($request->input('download_token') ?: $request->input('payload.download_token', ''));
        ProcessZoomRecordingJob::dispatch($model, $files, $token);
    }

    private function participantJoined(Model $model, Request $request): void
    {
        if ($model->join_time !== null) {
            return; // أوّل دخول فقط
        }
        $join = $request->input('payload.object.participant.join_time');
        if ($join) {
            // طوابع Zoom بتوقيت UTC (…Z)؛ نحوّلها لتوقيت التطبيق كي يتّسق التخزين والقراءة
            $model->update(['join_time' => Carbon::parse($join)->setTimezone(config('app.timezone'))]);
        }
    }

    private function participantLeft(Model $model, Request $request): void
    {
        $leave = $request->input('payload.object.participant.leave_time');
        if (! $leave) {
            return;
        }
        $leaveAt = Carbon::parse($leave)->setTimezone(config('app.timezone'));
        $data = ['leave_time' => $leaveAt];
        if ($model->join_time !== null) {
            $data['duration_sec'] = (int) abs($leaveAt->diffInSeconds($model->join_time));
        }
        $model->update($data);
    }

    // لا نتراجع عن جلسة منتهية (الحدث قد يصل متأخّراً/مكرّراً)
    private function startConsultSession(Consult $consult): void
    {
        if ($consult->session === 'منتهية' || $consult->session === 'جلسة جارية') {
            return;
        }

        $revived = $consult->status === 'لم يحضر';
        // الحارسان أعلاه يُقرآن قبل القفل؛ فإن ختمها الطاقم بينهما يرفض المحرّك — والويبهوك
        // يبقى صامتاً كما كان (Zoom يعيد إرسال ما لم يُجَب بـ200)
        try {
            Workflow::run(new ZoomSessionStarted, $consult);
        } catch (TransitionDenied) {
            return;
        }
        Live::push(new ConsultStatusBroadcast($consult));

        // **انعقادٌ بعد «لم يحضر» لا يمرّ صامتاً** — Zoom دليلُ انعقادٍ فيُقبل، لكنّ الإدارة تعلم
        // أنّ ملفّاً وُسم غائباً عاد إلى الجلسة (ربّما وُسم مبكّراً أو حضر العميل متأخّراً).
        if ($revived) {
            Audit::log(
                action: 'انعقاد جلسة بعد وسم «لم يحضر»',
                description: "بدأ اجتماع Zoom للاستشارة {$consult->ref} بعد وسمها «لم يحضر» — عادت إلى الجلسة.",
                category: 'استشارات',
                severity: 'warning',
                auditable: $consult,
            );
            foreach (User::where('role', Role::Admin)->pluck('id') as $adminId) {
                Notify::send($adminId, 'video', 't-amber', "الاستشارة ({$consult->ref}) وُسمت «لم يحضر» ثمّ بدأ اجتماعها على Zoom — عادت إلى الجلسة.");
            }
        }
    }

    /**
     * إنهاء الاستشارة من الويبهوك — يُكمل نفس دورة الإنهاء اليدويّ، نظير `endMeeting`.
     *
     * كان يكتفي بضبط الحالة، و`FinalizeConsultJob` يُطلَق من موضعٍ واحد: زرّ «إنهاء»
     * لدى الطاقم. فجلسةٌ خرج منها الطرفان بلا أن يضغط أحدٌ الزرّ تُختَم بلا ملخّص
     * ولا تنبيه — سكوتٌ تامّ.
     *
     * **ويُطلق التوليد مباشرةً لا «ينبّه فقط»:** بعد حارس «بلا مادّة ⇒ لا نداء» صارت
     * الوظيفةُ نقطةَ القرار الوحيدة — تجد مادّةً فتولّد، أو لا تجد فتنبّه المحامي.
     * ولو قرّر الويبهوك لتكرّرت قاعدة «ما هي المادّة؟» في موضعٍ ثانٍ فتباعدت الصياغتان.
     *
     * وثلاث طبقات تمنع التكرار: الفرع المحروس أدناه · `filled($summary)` في الوظيفة ·
     * `session_finalized_at` للتنبيه.
     */
    private function endConsult(Consult $consult): void
    {
        if ($consult->session === 'منتهية') {
            return; // حدثٌ متأخّر/مكرّر — أو ختمه الطاقم بالفعل
        }

        // الختم وحسم الموعد في انتقالٍ واحد مع زرّ الطاقم (`Staff\ConsultController::end`) — انظر `EndSession`
        try {
            Workflow::run(new EndSession, $consult, null, ['source' => 'zoom']);
        } catch (TransitionDenied) {
            return; // ختمه الطاقم بين القراءة والقفل
        }
        ConsultSessionOutcome::sessionEnded($consult);
        Live::push(new ConsultStatusBroadcast($consult));

        FinalizeConsultJob::dispatch($consult->fresh(), '');
    }

    /**
     * إنهاء الاجتماع من الويبهوك — يُكمل نفس دورة الإنهاء اليدوي (Staff\MeetingController@end):
     * كان يكتفي بضبط الحالة فتبقى دعوة العميل عالقة في «تنفيذ الجلسة» بلا ملخّص ولا حضور.
     */
    private function endMeeting(Meeting $meeting): void
    {
        if (in_array($meeting->status, ['منتهٍ', 'ملغى'], true)) {
            return; // حالة نهائية — الحدث متأخّر/مكرّر
        }

        $meeting->update([
            'status' => 'منتهٍ',
            // لا نسبة حضور مختلقة (كانت 90 مثبّتة): 0 = غير مسجَّلة وتُخفى من العرض
            'attend' => $meeting->attend ?: 0,
        ]);
        MeetRequest::where('meeting_id', $meeting->id)
            ->where('stage', '<', MeetRequest::STAGE_EXECUTED)
            ->update(['stage' => MeetRequest::STAGE_EXECUTED]);
        Live::push(new MeetingStatusBroadcast($meeting));

        GenerateMeetingSummaryJob::dispatch($meeting, '');
    }

    private function setMeetingStatus(Meeting $meeting, string $status): void
    {
        // الحالات النهائية لا تُحدَّث بحدث Zoom متأخّر: «منتهٍ» و«ملغى»
        // (فلا يُحيي حدث started/ended مطابور قبل الحذف اجتماعًا أُلغي).
        if (in_array($meeting->status, ['منتهٍ', 'ملغى'], true) || $meeting->status === $status) {
            return;
        }

        $meeting->update(['status' => $status]);
        Live::push(new MeetingStatusBroadcast($meeting));
    }
}
