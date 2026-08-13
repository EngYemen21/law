<?php

namespace App\Http\Controllers;

use App\Events\ConsultStatusBroadcast;
use App\Events\MeetingStatusBroadcast;
use App\Jobs\ProcessZoomRecordingJob;
use App\Jobs\ProcessZoomSummaryJob;
use App\Models\Consult;
use App\Models\Meeting;
use App\Support\Live;
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
            'meeting.started' => $this->setConsultSession($consult, 'جلسة جارية', 'قيد الاستشارة'),
            'meeting.ended' => $this->setConsultSession($consult, 'منتهية', 'منتهية'),
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
            'meeting.ended' => $this->setMeetingStatus($meeting, 'منتهٍ'),
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
    private function setConsultSession(Consult $consult, string $session, string $status): void
    {
        if ($consult->session === 'منتهية' || $consult->session === $session) {
            return;
        }

        $consult->update(['session' => $session, 'status' => $status]);
        Live::push(new ConsultStatusBroadcast($consult));
    }

    private function setMeetingStatus(Meeting $meeting, string $status): void
    {
        // الحالات النهائية لا تُحدَّث بحدث Zoom متأخّر: «منتهٍ» و«ملغى»
        // (فلا يُحيي حدث started/ended مطابور قبل الحذف اجتماعًا أُلغي).
        if (in_array($meeting->status, ['منتهٍ', 'ملغى'], true) || $meeting->status === $status) {
            return;
        }

        $meeting->update(['status' => $status, 'is_up' => $status !== 'منتهٍ']);
        Live::push(new MeetingStatusBroadcast($meeting));
    }
}
