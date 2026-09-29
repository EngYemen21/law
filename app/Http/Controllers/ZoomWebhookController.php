<?php

namespace App\Http\Controllers;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Consult\EndSession;
use App\Domain\Journey\Transitions\Consult\ZoomSessionStarted;
use App\Domain\Journey\Transitions\Meeting\EndMeeting;
use App\Domain\Journey\Transitions\Meeting\StartMeeting;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Events\Journey\SessionEndedInSystem;
use App\Events\RoomStateChanged;
use App\Jobs\GenerateMeetingSummaryJob;
use App\Jobs\ProcessZoomRecordingJob;
use App\Jobs\ProcessZoomSummaryJob;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Support\Audit;
use App\Support\ConsultSessionOutcome;
use App\Support\Live;
use App\Support\Notify;
use App\Support\RoomPresence;
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

    /**
     * **أحداث Zoom اللحظيّة** (قرار المالك 2026-09-26: «تواصل لحظي بالأحداث مع Zoom API»).
     *
     * ما يغيّر حالة الرحلة يمرّ بالمحرّك (البدء `ZoomSessionStarted`/`StartMeeting`، والإنهاء
     * `EndSession`/`EndMeeting`) فيبثّ `RoomStateChanged` بعد التزامه؛ وما هو حالُ الغرفة العابر
     * (من فيها، والتسجيل) يُحفظ في `RoomPresence` ويُبثّ فوراً. الاشتراكات المطلوبة في تطبيق Zoom:
     * `meeting.started` · `meeting.ended` · `meeting.participant_joined` · `meeting.participant_left`
     * · `recording.started` · `recording.stopped` · `recording.paused` · `recording.resumed`
     * · `recording.completed` · `meeting.summary_completed`.
     */
    private function routeConsult(string $event, Consult $consult, Request $request): void
    {
        match ($event) {
            'meeting.started' => $this->startConsultSession($consult),
            'meeting.ended' => $this->endConsult($consult),
            'meeting.summary_completed' => ProcessZoomSummaryJob::dispatch($consult, (array) $request->input('payload.object')),
            'recording.completed' => $this->recording($consult, $request),
            'recording.started', 'recording.resumed' => $this->recordingState($consult, true),
            'recording.stopped', 'recording.paused' => $this->recordingState($consult, false),
            'meeting.participant_joined' => $this->participantJoined($consult, $request),
            'meeting.participant_left' => $this->participantLeft($consult, $request),
            default => null,
        };
    }

    private function routeMeeting(string $event, Meeting $meeting, Request $request): void
    {
        match ($event) {
            'meeting.started' => $this->startMeeting($meeting),
            'meeting.ended' => $this->endMeeting($meeting),
            'meeting.summary_completed' => ProcessZoomSummaryJob::dispatch($meeting, (array) $request->input('payload.object')),
            'recording.completed' => $this->recording($meeting, $request),
            'recording.started', 'recording.resumed' => $this->recordingState($meeting, true),
            'recording.stopped', 'recording.paused' => $this->recordingState($meeting, false),
            'meeting.participant_joined' => $this->participantJoined($meeting, $request),
            'meeting.participant_left' => $this->participantLeft($meeting, $request),
            default => null,
        };
    }

    /** بدء/إيقاف التسجيل السحابيّ — حالُ غرفةٍ لا حالةُ رحلة؛ يصل الطاقمَ وحده (`RoomStateChanged`). */
    private function recordingState(Consult|Meeting $session, bool $on): void
    {
        RoomPresence::setRecording($session, $on);
        Live::push(...RoomStateChanged::both($session));
    }

    /**
     * معرّف المشارك داخل هذه الغرفة — `participant_uuid` ثابتٌ للمشارك في الاجتماع، وما بعده احتياطٌ
     * لحمولاتٍ أقدم. بلا معرّفٍ ⇒ لا يُحسب (لا يُخمَّن شخصٌ).
     */
    private function participantKey(Request $request): ?string
    {
        foreach (['participant_uuid', 'id', 'user_id', 'email', 'user_name'] as $field) {
            $value = (string) $request->input("payload.object.participant.{$field}", '');
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * **حسابُ الداخل من الطاقم** — غرفة المنصّة تعرّفه لـZoom بـ`customerKey` = `u{id}`
     * (`ZoomController::sdkSignature`) فيعود في الحدث `customer_key`. الحمولة موقَّعة (HMAC أعلاه)،
     * والحساب يُتحقَّق منه: طاقمٌ لا عميل. من دخل برابط Zoom الخارجيّ لا مفتاح له ⇒ لا يُخمَّن.
     */
    private function staffId(Request $request): ?int
    {
        $key = (string) $request->input('payload.object.participant.customer_key', '');
        if (! preg_match('/^u(\d+)$/', $key, $m)) {
            return null;
        }
        $user = User::find((int) $m[1]);

        return $user !== null && ! $user->isClient() ? $user->id : null;
    }

    // التسجيل/النصّ ثقيلان نسبياً — يُنزَّلان بعد إرسال الاستجابة (الـwebhook يردّ 200 فوراً)
    private function recording(Model $model, Request $request): void
    {
        $files = (array) $request->input('payload.object.recording_files', []);
        $token = (string) ($request->input('download_token') ?: $request->input('payload.download_token', ''));
        ProcessZoomRecordingJob::dispatch($model, $files, $token);
    }

    private function participantJoined(Consult|Meeting $model, Request $request): void
    {
        // من في الغرفة الآن — يُحسب لكلّ دخول (لا الأوّل وحده) ويُبثّ لصفحة الغرفة لحظيّاً
        if (($key = $this->participantKey($request)) !== null) {
            RoomPresence::participantJoined($model, $key, $this->staffId($request));
        }

        $join = $request->input('payload.object.participant.join_time');
        // `join_time` أوّلُ دخولٍ فقط — منه تُقاس المدّة وشبكة النسيان
        if ($model->join_time === null && $join) {
            // طوابع Zoom بتوقيت UTC (…Z)؛ نحوّلها لتوقيت التطبيق كي يتّسق التخزين والقراءة
            $model->update(['join_time' => Carbon::parse($join)->setTimezone(config('app.timezone'))]);
        }

        Live::push(...RoomStateChanged::both($model));
    }

    private function participantLeft(Consult|Meeting $model, Request $request): void
    {
        if (($key = $this->participantKey($request)) !== null) {
            RoomPresence::participantLeft($model, $key);
        }

        $leave = $request->input('payload.object.participant.leave_time');
        if ($leave) {
            $leaveAt = Carbon::parse($leave)->setTimezone(config('app.timezone'));
            $data = ['leave_time' => $leaveAt];
            if ($model->join_time !== null) {
                $data['duration_sec'] = (int) abs($leaveAt->diffInSeconds($model->join_time));
            }
            $model->update($data);
        }

        Live::push(...RoomStateChanged::both($model));
    }

    // لا نتراجع عن جلسة منتهية (الحدث قد يصل متأخّراً/مكرّراً)
    private function startConsultSession(Consult $consult): void
    {
        if ($consult->session === SessionState::Ended->value || $consult->isLive()) {
            return;
        }

        $revived = ConsultStatus::tryFrom((string) $consult->status) === ConsultStatus::NoShow;
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
        // Zoom يقول: الغرفة أُغلقت — لا يبقى عددٌ ولا تسجيلٌ بائت، أيّاً كان من أنهاها
        RoomPresence::forget($consult);

        if ($consult->session === SessionState::Ended->value) {
            return; // حدثٌ متأخّر/مكرّر — أو ختمه الطاقم بالفعل
        }

        // الختم وحسم الموعد في انتقالٍ واحد مع زرّ الطاقم (`Staff\ConsultController::end`) — انظر `EndSession`
        try {
            // المصدر Zoom ⇒ لا يُنادى Zoom لإغلاق غرفةٍ أغلقها هو (`SessionEndedInSystem`)
            Workflow::run(new EndSession, $consult, null, ['source' => SessionEndedInSystem::VIA_ZOOM]);
        } catch (TransitionDenied) {
            return; // ختمه الطاقم بين القراءة والقفل
        }
        ConsultSessionOutcome::afterUnattendedEnd($consult);
    }

    /**
     * إنهاء الاجتماع من الويبهوك — يُكمل نفس دورة الإنهاء اليدوي (Staff\MeetingController@end):
     * كان يكتفي بضبط الحالة فتبقى دعوة العميل عالقة في «تنفيذ الجلسة» بلا ملخّص ولا حضور.
     */
    private function endMeeting(Meeting $meeting): void
    {
        RoomPresence::forget($meeting);

        // الختم والحضور ورفع الدعوة والبثّ في انتقالٍ واحد مع زرّ الطاقم — انظر `EndMeeting`.
        // الحالة النهائيّة يرفضها حارسه: حدثٌ متأخّر/مكرّر يُمتصّ بصمت (Zoom يعيد ما لم يُجَب بـ200)
        try {
            Workflow::run(new EndMeeting, $meeting, null, ['source' => SessionEndedInSystem::VIA_ZOOM]);
        } catch (TransitionDenied) {
            return;
        }

        GenerateMeetingSummaryJob::dispatch($meeting, '');
    }

    /**
     * Zoom يُعلن بدء الاجتماع ⇐ «جارٍ» بانتقال البدء نفسه الذي يسلكه زرّ الطاقم (`StartMeeting`،
     * بمصدر Zoom: يُقبل من أيّ حالةٍ غير نهائيّة). كان يُكتب هنا مباشرةً بمقارنةٍ نصّيّة. والنهائيّ
     * والجاري أصلاً يرفضهما الحارس: حدثٌ متأخّر/مكرّر يُمتصّ بصمت (Zoom يعيد ما لم يُجَب بـ200).
     */
    private function startMeeting(Meeting $meeting): void
    {
        try {
            Workflow::run(new StartMeeting, $meeting, null, ['source' => SessionEndedInSystem::VIA_ZOOM]);
        } catch (TransitionDenied) {
            return;
        }
    }
}
