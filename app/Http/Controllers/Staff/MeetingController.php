<?php

namespace App\Http\Controllers\Staff;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Domain\Journey\Enums\RescheduleReason;
use App\Domain\Journey\Transitions\Meeting\CancelMeeting;
use App\Domain\Journey\Transitions\Meeting\EndMeeting;
use App\Domain\Journey\Transitions\Meeting\StartMeeting;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Events\Journey\MeetingCancelled;
use App\Events\Journey\SessionEndedInSystem;
use App\Events\MeetingStatusBroadcast;
use App\Http\Controllers\Concerns\ScopedToLawyer;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateMeetingSummaryJob;
use App\Mail\MeetingEndedMail;
use App\Mail\MeetingEventMail;
use App\Mail\MeetingScheduledMail;
use App\Mail\MeetInviteMail;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\Task;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Services\LegalAiService;
use App\Services\MailService;
use App\Services\ZoomService;
use App\Support\Audit;
use App\Support\Booking\BookingMoment;
use App\Support\Booking\BookingMoved;
use App\Support\ClientDirectory;
use App\Support\DecisionTasks;
use App\Support\LawyerAvailability;
use App\Support\Live;
use App\Support\MeetingSummary;
use App\Support\Notify;
use App\Support\RecordingArchive;
use App\Support\ReferenceNumber;
use App\Support\RoomDetails;
use App\Support\SessionWindow;
use App\Support\SettingsRegistry;
use App\Support\WebTimeLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * اجتماعات المكتب (يطابق lwMeetings/meetingView/meetMgmtView/adMeetings/meetLogView/meetReportsView).
 */
class MeetingController extends Controller
{
    use ScopedToLawyer;

    public function __construct(private ZoomService $zoom, private LegalAiService $ai) {}

    // قائمة الاجتماعات (المحامي) / اعتماد الاجتماعات (الإدارة)
    public function index(Request $request): Response
    {
        return Inertia::render($this->prefix($request).'/meetings', [
            'meetings' => $this->cards($request),
        ]);
    }

    // تفاصيل الاجتماع (يطابق meetingView) — ?id=M-…
    public function show(Request $request): Response
    {
        $meeting = Meeting::where('ref', (string) $request->query('id'))->firstOrFail();
        $this->guardMeetingView($request, $meeting);

        return Inertia::render($this->prefix($request).'/meeting', [
            'meeting' => $meeting->toFullCard(),
        ]);
    }

    // غرفة الاجتماع المضمّنة (Zoom Web SDK) — ?ref=M-…
    public function room(Request $request): Response|RedirectResponse
    {
        $meeting = Meeting::where('ref', (string) $request->query('ref'))->firstOrFail();
        $this->guardMeetingView($request, $meeting);
        // القاعدة الواحدة للغرف الأربع (`RoomDetails::entryBlocker`) — المنتهي والفائت والملغى
        // يُصَدّ بسببه الحقيقيّ؛ وإخفاء الزرّ في الواجهة وحده يُلتفّ عليه بالرابط المباشر.
        if (($why = RoomDetails::entryBlocker($meeting, $request->user())) !== null) {
            return RoomDetails::refuse($request, $meeting, $why, 422);
        }

        return Inertia::render($this->prefix($request).'/meetingroom', [
            // عقد الغرفة (`RoomDetails::for`) — والخاصيّتان القديمتان باقيتان حتى تنتقل الواجهة إليه
            'room' => RoomDetails::for($meeting, $request->user()),
            'meeting' => $meeting->toFullCard(),
            'selfName' => $request->user()->name,
        ]);
    }

    // لوحة إدارة الاجتماعات (يطابق meetMgmtView)
    public function mgmt(Request $request): Response
    {
        return Inertia::render('admin/meetmgmt', [
            'meetings' => $this->cards($request),
            'clients' => ClientDirectory::list(),
            // النشطون وحدهم — `store` يتحقّق بـ`ActiveLawyer`، فعرضُ الموقوف كان يُفضي إلى رفضٍ لا يُفهم
            'lawyers' => User::activeLawyers()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'staff' => User::whereIn('role', [Role::Lawyer, Role::Employee, Role::Admin])
                ->where('status', '!=', 'suspended')
                ->orderBy('name')->get(['id', 'name', 'role', 'department'])
                // الدور بتسميته العربيّة (`Role::label`) — `value` لاتينيّ («lawyer») كان يظهر للمستخدم
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'role' => $u->role?->label() ?? 'كادر',
                    'label' => "{$u->name} (".($u->department ?: ($u->role?->label() ?? 'كادر')).')',
                ]),
            'kpis' => $this->kpis(),
        ]);
    }

    /** مؤشرات حقيقية للوحة الإدارة: تنفيذ القرارات (مهام منجزة/الكل) + متوسط مدة المنتهية (دقائق). */
    private function kpis(): array
    {
        $refs = Meeting::query()->pluck('ref')->all();
        $totalTasks = $refs !== [] ? Task::whereIn('ref', $refs)->count() : 0;
        $doneTasks = $refs !== [] ? Task::whereIn('ref', $refs)->where('status', 'منجزة')->count() : 0;

        $durations = Meeting::where('status', MeetingStatus::Ended->value)->whereNotNull('duration_sec')->pluck('duration_sec');

        return [
            // null = لا مقياس بعد (لا مهامّ / لا مدد فعلية) — الصفر يدّعي قياساً وقع
            'decisionRate' => $totalTasks > 0 ? (int) round($doneTasks / $totalTasks * 100) : null,
            'avgMinutes' => $durations->count() ? (int) round(((float) $durations->avg()) / 60) : null,
        ];
    }

    // إنشاء اجتماع جديد (يطابق submitMeeting) — مع جلسة Zoom ودعوة العميل إن رُبط
    public function store(Request $request): RedirectResponse
    {
        // **مسارٌ يخرج إلى الشبكة مراراً** (رمز Zoom ٨ث + إنشاء الجلسة ١٥ث
        // + بريد) ومهلةُ الويب ٣٠ث — فتُبلَغ فيرى المستخدم خطأً
        // **والسجلّ كُتب فعلاً** (الالتزام يسبق النداء). رُصد حيّاً 2026-09-08.
        WebTimeLimit::raise(90);
        // **التاريخ مطلوب.** كان `day`/`time` سلسلتين حرّتين اختياريّتين،
        // وحين تُتركان يُخترَع «اليوم · 10:00» — اجتماعٌ بموعدٍ لم يختره أحد.
        $data = $request->validate(BookingMoment::rules() + [
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', 'max:60'],
            'priority' => ['nullable', 'string', 'in:عالية,متوسطة,عادية'],
            'conf' => ['nullable', 'string', 'in:سري,عادي'],
            // لا حقل «المدة» (قرار المالك 2026-09-26): الاجتماع ينتهي حين يُنهى — `EndMeeting`
            // المشاركون من الكادر بمعرّفاتهم (`meeting_participants`) — كانوا نصّاً يُطابَق بالاسم
            'participant_ids' => ['nullable', 'array', 'max:50'],
            'participant_ids.*' => ['integer', Rule::exists('users', 'id')->whereIn('role', [Role::Lawyer->value, Role::Employee->value, Role::Admin->value])],
            'client_id' => ['nullable', 'integer', 'exists:users,id'],
            'case_ref' => ['nullable', 'string', 'max:120'],
            'lawyer_id' => ['nullable', 'integer', new ActiveLawyer],
        ]);

        $client = ! empty($data['client_id']) ? User::find($data['client_id']) : null;
        // المحامي المسؤول: المختار صراحةً (يراه في قائمته)، وإلا المنشئ إن كان محامياً
        $assignedLawyer = ! empty($data['lawyer_id'])
            ? User::where('role', Role::Lawyer)->find((int) $data['lawyer_id'])
            : ($request->user()->role === Role::Lawyer ? $request->user() : null);
        // اللحظة مصدر الوقت **والعرض** معاً — فلا يقول `when_label` شيئاً
        // و`starts_at` شيئاً آخر (أو لا يقول شيئاً).
        $moment = BookingMoment::from($data['day'], $data['time']);
        $startsAt = $moment->startsAt;
        $when = $moment->label();

        // **حرّاس الموعد كمسار الدعوات** (`MeetRequestController::store`) — كان الإنشاء من هنا يقبل موعداً
        // مضى، وموعداً فوق ارتباطٍ آخر للمحامي المسؤول (ثبت بالاختبار 2026-09-28)
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['time' => 'لا يمكن اختيار موعد ماضٍ — اختر وقتاً لاحقاً.']);
        }
        if ($assignedLawyer && LawyerAvailability::isBusy($assignedLawyer->id, Carbon::parse($startsAt))) {
            throw ValidationException::withMessages(['time' => 'المحامي مشغول في هذا الوقت — اختر وقتاً آخر أو محامياً مختلفاً.']);
        }
        // والمشاركون كالمسؤول: وقتهم محجوبٌ في المحرّك، فلا يُضاف مشاركٌ مشغول (قرار المالك: رفضٌ باسمه)
        $participantIds = array_values(array_diff(array_map('intval', $data['participant_ids'] ?? []), [(int) $assignedLawyer?->id]));
        if ($busy = LawyerAvailability::busyAmong($participantIds, Carbon::parse($startsAt))) {
            $names = User::whereKey($busy)->pluck('name')->implode('، ');
            throw ValidationException::withMessages(['participant_ids' => "مشغولٌ في هذا الوقت: {$names} — أزِله من المشاركين أو اختر وقتاً آخر."]);
        }

        // Zoom يشترط `duration` — رقمٌ اسميّ لا يُنهي الاجتماع به (`SessionWindow::nominalMinutes`)
        $zoom = $this->zoom->createMeeting($data['title'], SessionWindow::nominalMinutes(), ($data['conf'] ?? '') === 'سري', $startsAt);
        if (empty($zoom['join_url'])) {
            // لا يُصمت: الاجتماع يُحفظ بلا رابط، والرسالة تقول ذلك (كانت تقول «أُنشئ بجلسة Zoom»)
            Log::error('Zoom: تعذّر إنشاء جلسة الاجتماع — يُحفظ بلا رابط', ['title' => $data['title']]);
        }

        /*
         * **الإنشاء في سجلّ الرحلة وفي معاملةٍ واحدة** (`Workflow::open`): الاجتماع ودعوة العميل معاً أو لا
         * شيء — وكانت جلسة Zoom تبقى يتيمةً إن فشل الحفظ بعد إنشائها، فتُحذف هنا.
         */
        $meetRequest = null;

        try {
            $meeting = Workflow::open('meeting.create', function () use ($data, $client, $when, $startsAt, $zoom, $assignedLawyer, $participantIds, $request, &$meetRequest) {
                $meeting = Meeting::create([
                    'user_id' => $client?->id,
                    // المولّد الموحّد يفحص التكرار — كان ٩٠٠ رقمٍ في السنة بلا فحصٍ على عمودٍ فريد فيفشل الإنشاء
                    'ref' => ReferenceNumber::next(Meeting::class, 'ref', 'M'),
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'client_name' => $client?->name ?: 'داخلي',
                    'when_label' => $when,
                    'starts_at' => $startsAt,
                    // لا «بانتظار التأكيد»: تأكيد العميل أُلغي، فالاجتماع مجدول منذ إنشائه
                    'status' => MeetingStatus::Upcoming->value,
                    'priority' => $data['priority'] ?? 'عادية',
                    'conf' => $data['conf'] ?? 'عادي',
                    // `dur` لا يُكتب: لا مدّة للاجتماع، ومسافته على تقويم المحامي طولُ شريحة الحجز
                    'case_ref' => ($data['case_ref'] ?? '') ?: null,
                    'meet_id' => $zoom['id'] ?? null,
                    'meet_link' => $zoom['join_url'] ?? null,
                    'host_link' => $zoom['start_url'] ?? null,
                    'meet_password' => $zoom['password'] ?? null,
                    'created_by' => $request->user()->name,
                    // عزل الرؤية/البثّ: المحامي المسؤول (المحامي يرى المسنَد إليه وحده)
                    'assigned_lawyer_id' => $assignedLawyer?->id,
                    'has_link' => ! empty($zoom['join_url']),
                ]);

                // المسؤول ليس «مشاركاً» — له صفته وصلاحيّاته
                $meeting->participantUsers()->sync($participantIds);

                // دعوة رسميّة مؤكَّدة يراجع العميل منها تفاصيل الاجتماع (تأكيد الحضور مُلغى)
                $meetRequest = $client === null ? null : MeetRequest::create([
                    'user_id' => $client->id,
                    'meeting_id' => $meeting->id,
                    'ref' => ReferenceNumber::next(MeetRequest::class, 'ref', 'MR'),
                    'service' => $data['title'],
                    'type' => $data['type'],
                    'case_ref' => ($data['case_ref'] ?? '') ?: null,
                    'day' => $data['day'],
                    'time' => $data['time'],
                    'assigned_lawyer_id' => $assignedLawyer?->id,
                    'sent_by' => $request->user()->name.' ('.$request->user()->role->label().')',
                    'sent_by_id' => $request->user()->id,
                    'stage' => MeetRequest::STAGE_CONFIRMED, // مؤكَّدة منذ الإرسال (لا تأكيد من العميل)
                    'meet_id' => $zoom['id'] ?? null,
                    'meet_link' => $zoom['join_url'] ?? null,
                    'host_link' => $zoom['start_url'] ?? null,
                ]);

                return $meeting;
            }, $request->user(), ['client_id' => $client?->id, 'lawyer_id' => $assignedLawyer?->id, 'zoom' => ! empty($zoom['join_url'])]);
        } catch (\Throwable $e) {
            if (! empty($zoom['id'])) {
                $this->zoom->deleteMeeting((string) $zoom['id']);
            }
            throw $e;
        }

        // إشعار (داخل التطبيق + بريد) بموعد الاجتماع — توجيه آمن داخل المنصّة لكل دور (لا روابط خارجية)
        if ($client) {
            // إشعار بموعد مجدول لا بطلب تأكيد
            Notify::send($client->id, 'video', 't-blue', "اجتماع مجدول: «{$meeting->title}» ({$when}) — تجده في قسم الاجتماعات بالمنصة.");
            app(MailService::class)->send($client, new MeetInviteMail($meetRequest));
        }

        if ($assignedLawyer) {
            $lawyerUrl = $meeting->portalUrlFor($assignedLawyer);
            $msg = $client
                ? "تمت جدولة اجتماع «{$meeting->title}» — {$when} مع العميل."
                : "تم تكليفك باجتماع داخلي «{$meeting->title}» — {$when}.";
            Notify::send($assignedLawyer->id, 'video', 't-blue', $msg);
            app(MailService::class)->send($assignedLawyer, new MeetingScheduledMail(
                $assignedLawyer->name,
                $meeting->title,
                $when,
                $lawyerUrl,
                'داخل لوحة المحامي (قسم طلبات الاجتماعات)',
                $client ? 'أُرسلت الدعوة للعميل — الاجتماع مؤكد ومنشور في منصته.' : 'يرجى الدخول للوحة المحامي للاطلاع على تفاصيل الجلسة.'
            ));
        }

        // إشعار باقي الكادر القانوني والإداري المشارك في الجلسة (الموظفون/المحامون)
        // كانت الكتلة ميتة: $validated غير معرّف والمشاركون نصّ واحد لا مصفوفة — فلا يصل أي إشعار
        // بالحسابات المحفوظة لا بمطابقة الاسم — والمسؤول أُشعر أعلاه ولم يُحفظ مشاركاً
        foreach ($meeting->participantUsers()->get() as $staffUser) {
            $staffUrl = $meeting->portalUrlFor($staffUser);
            Notify::send($staffUser->id, 'video', 't-blue', "تمت إضافتك كمشارك في اجتماع «{$meeting->title}» — {$when}. متاح في لوحتك.");
            app(MailService::class)->send($staffUser, new MeetingScheduledMail(
                $staffUser->name,
                $meeting->title,
                $when,
                $staffUrl,
                // اسم المكتب من الإعدادات لا منقوشاً
                'داخل '.SettingsRegistry::str('office_name'),
                'تمت إضافتك كمشارك في الاجتماع من قِبل الإدارة، يرجى تسجيل الدخول للمنصة للحضور.'
            ));
        }

        return back()->with('flash', $meeting->meet_link
            ? "أُنشئ الاجتماع {$meeting->ref} بجلسة Zoom وأُضيف للتقويم."
            : "أُنشئ الاجتماع {$meeting->ref} وأُضيف للتقويم — بلا رابط Zoom (تعذّر إنشاؤه)، أضِفه لاحقاً من صفحة الاجتماع.");
    }

    // حفظ ملخص الاجتماع (المحامي)
    public function saveSummary(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        // الاعتماد نهائيّ: المعتمد وصل العميل بشهادة الإدارة، فتعديله بعدها
        // يجعل الشهادة تصف نصّاً لا وجود له. والحفظ قبل الاعتماد مسوّدة تُعدَّل بحرّية.
        abort_if($meeting->approve === 'معتمد', 422, 'المحضر والملخص معتمدان نهائيًّا من الإدارة — لا يُعدَّلان بعد الاعتماد.');
        $data = $request->validate(['summary' => ['required', 'string', 'max:6000']]);
        $meeting->update(['summary' => $data['summary']]);

        return back();
    }

    // حفظ محضر الاجتماع (المحامي)
    public function saveMinutes(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        // الاعتماد نهائيّ: المعتمد وصل العميل بشهادة الإدارة، فتعديله بعدها
        // يجعل الشهادة تصف نصّاً لا وجود له. والحفظ قبل الاعتماد مسوّدة تُعدَّل بحرّية.
        abort_if($meeting->approve === 'معتمد', 422, 'المحضر والملخص معتمدان نهائيًّا من الإدارة — لا يُعدَّلان بعد الاعتماد.');
        $data = $request->validate(['minutes' => ['required', 'string', 'max:8000']]);
        $meeting->update(['minutes' => $data['minutes']]);

        return back();
    }

    /**
     * استعلام يدوي من Zoom API: يسحب كل بيانات الجلسة (الحضور، المدة، التسجيل، النصّ،
     * الملخص) ويحدّث الاجتماع فوراً — لحالات تأخّر الويبهوك/السحب الدوري أو تعثّرهما.
     */
    public function zoomSync(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        abort_if(empty($meeting->meet_id), 422, 'لا جلسة Zoom مرتبطة بهذا الاجتماع.');
        // الاعتماد نهائيّ: المزامنة تكتب `decisions` عبر DecisionTasks::suggest، وtoCard
        // يُرسل القرارات للعميل متى كان الاجتماع معتمداً — فمزامنةٌ بعد الاعتماد تُبلغه
        // قراراتٍ لم تعتمدها الإدارة. وتُعيد كتابة `participants` الذي يُبنى عليه عدد المدعوّين.
        abort_if($meeting->approve === 'معتمد', 422, 'الاجتماع معتمد نهائيًّا — لا تُحدَّث بياناته من Zoom بعد الاعتماد.');

        $pulled = MeetingSummary::pull($meeting, $this->zoom);

        return back()->with(
            $pulled ? 'flash' : 'error',
            $pulled
                ? 'تم تحديث بيانات الجلسة من Zoom.'
                : 'لا بيانات جديدة لدى Zoom بعد — الملخص يُعدّ عادةً خلال دقائق من انتهاء جلسة فعلية.'
        );
    }

    // اعتماد الاجتماع ومحضره (الإدارة — يطابق mApprove) → يظهر المحضر والملخص للعميل
    public function approve(Request $request, Meeting $meeting): RedirectResponse
    {
        if (! $meeting->isApproved()) {
            // الاعتماد بعد اكتمال الجلسة وتوفّر مخرجات **حقيقية** فقط — القاعدة في النموذج
            // (`Meeting::approvalBlocker`) لأنّ البطاقة تقرؤها أيضاً علَماً (`canApprove`) فلا يُعرض زرٌّ يُردّ.
            $why = $meeting->approvalBlocker();
            abort_if($why !== null, 422, (string) $why);
            $meeting->update([
                'approve' => 'معتمد',
                'sum_approved' => true,
                'has_minutes' => filled($meeting->minutes),
                'has_summary' => filled($meeting->summary),
            ]);
            MeetRequest::where('meeting_id', $meeting->id)
                ->where('stage', '<', MeetRequest::STAGE_APPROVED)
                ->update(['stage' => MeetRequest::STAGE_APPROVED]);

            if ($meeting->user_id && $meeting->user) {
                Notify::send($meeting->user_id, 'doc', 't-green', "اعتمدت الإدارة محضر وملخص اجتماع «{$meeting->title}» — متاحان الآن في صفحة الاجتماعات.");
                // بريد «انتهى الاجتماع» مع الملخّص المعتمد + رابط عرض المحضر
                app(MailService::class)->send($meeting->user, new MeetingEndedMail(
                    $meeting->user->name, $meeting->title, $meeting->summary, url('/meetings')
                ));
            }
            // بثّ الاعتماد → يصل الملخص/المحضر للعميل لحظياً
            Live::push(new MeetingStatusBroadcast($meeting->fresh()));
        }

        return back();
    }

    // إنهاء الاجتماع: تسجيل الحضور + توليد ملخص/محضر/قرارات بالذكاء الاصطناعي + بثّ لحظي
    public function end(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        $data = $request->validate([
            'attend' => ['nullable', 'integer', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        // **الإنهاء حدثٌ لا حساب** (قرار المالك 2026-09-26) — والختم في انتقالٍ واحد مع ويبهوك
        // Zoom (`EndMeeting`). «ملغى» كان يُختم هنا «منتهٍ» لأنّ الشرط قارن «منتهٍ» وحدها؛ حارس
        // الانتقال يرفض النهائيّتين معاً. وإغلاق غرفة Zoom حدثُ الانتقال بعد التزامه
        // (`EndZoomMeetingJob`) — كان نداءً متزامناً هنا قبل الختم يقف عليه الزرّ.
        //
        // **ولا يُنهى إلّا جارٍ** (قرار المالك 2026-09-26): كان الشرط «غير نهائيّ» وحده، فيُنهى
        // اجتماعٌ «قادم» لم يبدأ ويُطلب محضره. الحارس في الانتقال (`EndMeeting::guard` ⇐
        // `Meeting::isLive`) برسالته، لا هنا. والمنتهي أصلاً (نقرةٌ ثانية) يُعاد بصمت كما كان.
        if (! MeetingStatus::isFinalValue($meeting->status)) {
            Workflow::run(new EndMeeting, $meeting, $request->user(), array_filter([
                'source' => SessionEndedInSystem::VIA_STAFF,
                'attend' => $data['attend'] ?? null,
            ], fn ($v) => $v !== null));

            $notes = trim($data['notes'] ?? '');
            GenerateMeetingSummaryJob::dispatch($meeting, $notes);
        }

        return RoomDetails::afterEnd($meeting, $request->user(), 'انتهى الاجتماع وأُغلقت غرفته — يُعدّ محضره الآن.');
    }

    // بدء الاجتماع يدويًا (قادم/مؤجل → جارٍ) — للاجتماعات المجدولة مباشرةً بلا دعوة
    public function start(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        // بدأه Zoom أو زميلٌ قبل ثوانٍ — تكرارُ الفعل ليس خطأً
        if ($meeting->status === MeetingStatus::Live->value) {
            return back();
        }

        // الكتابة والحارس ورفع الدعوة والبثّ في الانتقال (`StartMeeting`) — كان «جارٍ» يُكتب هنا
        // مباشرةً بلا سطرٍ في سجلّ الرحلة، و«لم ينعقد» يُتجاهل بصمت بدل أن يُقال لماذا.
        Workflow::run(new StartMeeting, $meeting, $request->user(), ['source' => SessionEndedInSystem::VIA_STAFF]);

        if ($meeting->user_id) {
            Notify::send($meeting->user_id, 'video', 't-cyan', "بدأت جلسة اجتماع «{$meeting->title}» — يمكنك الدخول الآن.");
        }

        return back();
    }

    // إعادة جدولة/تأجيل الاجتماع — يزامن الموعد مع Zoom (PATCH) ويعيد تسليح التذكير
    public function reschedule(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        // حارس خادمي: النهائية لا تُعاد جدولتها (إخفاء الزر في الواجهة وحده يُلتفّ عليه)
        abort_if(MeetingStatus::isFinalValue($meeting->status), 422, 'الاجتماع منتهٍ أو ملغى — أنشئ اجتماعاً جديداً بدل إعادة جدولته.');
        // **ولا ما انعقد فعلاً وإن لم تُحدَّث حالته بعد.** إعادة الجدولة تُصفّر بيانات جلسة Zoom
        // (أدناه) — وفي اجتماعٍ وقع، تلك البيانات هي تسجيله ودليل حضوره، فتُمحى بلا رجعة.
        abort_if($meeting->wasHeld(), 422, 'انعقد هذا الاجتماع — أنشئ اجتماعاً جديداً بدل إعادة جدولته.');
        // **سقف إعادة الجدولة** (قرار المالك 2026-09-29، `meeting_reschedule_limit`) — ما بعده للإدارة العليا
        // وحدها، كنظيره في الاستشارة (`RescheduleConsult::deny`)
        abort_if(! $request->user()->isAdmin() && $meeting->reachedRescheduleLimit(), 422,
            'بلغ الاجتماع الحدّ الأقصى لإعادة الجدولة ('.Meeting::rescheduleLimit().') — إعادة جدولته بعد ذلك للإدارة العليا وحدها.');
        // **موعدٌ مفهوم لا سلسلة حرّة.** كانت إعادة الجدولة تقبل أيّ نصّ،
        // فتُنتج `starts_at = null` وحالةً «مؤجّل» — واجتماعٌ بلا طابع زمنيّ
        // لا يصله تذكيرٌ أبداً (`meetings:send-reminders` يشترطه).
        // **و«أُجِّل بلا موعد» نيّةٌ صريحة لا حصيلةُ نصٍّ لا يُفكّ.** كانت الحالة
        // «مؤجل» تُبلَغ بكتابة أيّ نصٍّ غير مفهوم في حقل التاريخ، فيقع التأجيل
        // بالمصادفة؛ وتشديدُ الصيغة وحده كان سيجعلها **غير قابلة للبلوغ** رغم أن
        // لها تبويباً وعدّاداً في اللوحة — وهو مصير «بانتظار التأكيد» المعلّق أدناه.
        $postpone = $request->boolean('postpone');
        // **السبب إلزاميّ في المسارين** — التأجيل بلا موعد ليس أهون من النقل: كلاهما يُخلف موعداً
        // أُبلغ به العميل، وكان يقع بلا أثرٍ يقول لماذا.
        $data = $request->validate(array_merge(
            $postpone ? [] : BookingMoment::rules(),
            RescheduleReason::rules('meeting'),
        ));
        $reason = RescheduleReason::from($data['reason'])->describe($data['note'] ?? null);

        $moment = $postpone ? null : BookingMoment::from($data['day'], $data['time']);
        // **موعدٌ مضى ليس موعداً.** كان يُقبل، ثمّ يلتقطه `meetings:auto-close` فيغلقه «لم ينعقد» —
        // اجتماعٌ «أُعيدت جدولته» يموت في الدورة التالية للمجدول. ومسارات الحجز الأخرى ترفضه أصلاً.
        abort_if($moment?->isPast() === true, 422, 'لا يمكن نقل الاجتماع إلى موعدٍ مضى — اختر وقتاً لاحقاً.');
        $startsAt = $moment?->startsAt;
        // **ولا فوق ارتباطٍ آخر للمسؤول أو المشاركين** — كحارس الإنشاء (ثبت بالاختبار 2026-09-28 أنّ النقل
        // كان يقبل ما يرفضه الإنشاء). والاجتماع نفسه لا يحجب موعده الجديد.
        if ($startsAt) {
            if ($busy = LawyerAvailability::busyAmong($meeting->staffIds(), Carbon::parse($startsAt), null, $meeting->id)) {
                $names = User::whereKey($busy)->pluck('name')->implode('، ');
                throw ValidationException::withMessages(['time' => "مشغولٌ في هذا الوقت: {$names} — اختر وقتاً آخر."]);
            }
        }
        $when = $moment?->label() ?? 'يُحدَّد لاحقاً';
        $oldWhen = (string) ($meeting->when_label ?: '—');
        $oldStatus = (string) $meeting->status;

        $meeting->update([
            'status' => ($startsAt ? MeetingStatus::Upcoming : MeetingStatus::Postponed)->value,
            'when_label' => $when,
            'starts_at' => $startsAt,
            'reminder_sent_at' => null, // إعادة تسليح التذكير للموعد الجديد
            // بقايا جلسةٍ لم تنعقد (خروجٌ/مدّةٌ/حضورٌ يدويّ) لا تخصّ الموعد الجديد. ولا يُمسّ
            // `join_time` ولا `recording_url`: حارس «انعقد» أعلاه يضمن خلوّهما، ومحوهما كان يُضيع تسجيلاً.
            'leave_time' => null,
            'duration_sec' => null,
            'attend' => 0, // العمود غير قابل لـnull — صفر يعني «لم يُسجَّل حضور بعد»
            // تُعدّ إعادة الجدولة، ويُطوى طلب العميل القائم — فقد أُجيب
            'reschedule_count' => (int) $meeting->reschedule_count + 1,
            'reschedule_requested_at' => null,
        ]);

        // **Zoom يتبع الموعد** (ومدّته الاسميّة من `SessionWindow` — لا يُنهي الاجتماع بها).
        // **فقط عند موعدٍ مفهوم.** تاريخٌ لا يُفكّ يعني «أُجّل بلا موعد»
        // لا «أُلغي» — والخلط بينهما كان سيحذف اجتماع Zoom.
        if ($startsAt) {
            BookingMoved::apply($meeting->fresh(), $startsAt);
        }
        // دعوة نُفّذت جلستها أو انتهت صلاحيتها تعود «مؤكدة» بالموعد الجديد (المرسلة تبقى بانتظار العميل)
        MeetRequest::where('meeting_id', $meeting->id)
            ->whereIn('stage', [MeetRequest::STAGE_EXECUTED, MeetRequest::STAGE_EXPIRED])
            ->update(['stage' => MeetRequest::STAGE_CONFIRMED]);
        MeetRequest::where('meeting_id', $meeting->id)
            ->where('stage', '!=', MeetRequest::STAGE_CANCELLED)
            ->update(['day' => $data['day'] ?? $when, 'time' => ($data['time'] ?? '') ?: '—']);
        // العميل يعرف **لماذا** تغيّر موعده — لا أنّه تغيّر فحسب.
        if ($meeting->user_id) {
            Notify::send($meeting->user_id, 'cal', 't-amber', $startsAt
                ? "أُعيدت جدولة اجتماع «{$meeting->title}»: {$when} — السبب: {$reason}."
                : "أُجّل اجتماع «{$meeting->title}» بلا موعد — يصلك الموعد الجديد حين يُحدَّد. السبب: {$reason}.");
        }
        $this->mailMeetingEvent($meeting, 'rescheduled', $reason);
        Audit::log(
            action: $startsAt ? 'إعادة جدولة اجتماع' : 'تأجيل اجتماع',
            description: ($startsAt ? "نقل {$request->user()->name} الاجتماع «{$meeting->title}» ({$meeting->ref}) إلى {$when}" : "أجّل {$request->user()->name} الاجتماع «{$meeting->title}» ({$meeting->ref}) بلا موعد")
                ." ({$reason}) — كان موعده: {$oldWhen}.",
            category: 'اجتماعات',
            severity: 'warning',
            auditable: $meeting,
            beforeState: ['الموعد' => $oldWhen, 'الحالة' => $oldStatus],
            afterState: ['الموعد' => $when, 'الحالة' => (string) $meeting->status, 'السبب' => $reason],
        );
        Live::push(new MeetingStatusBroadcast($meeting));

        return back();
    }

    /**
     * إلغاء الاجتماع — بانتقال الإلغاء (`CancelMeeting`): «ملغى»، والدعوات المرتبطة، وحذفُ غرفة
     * Zoom في الطابور (`MeetingCancelled`)، والبثّ والتدقيق. **والجاري لا يُلغى** — يرفضه الحارس
     * بسببه (قرار المالك 2026-09-26). والملغى أصلاً (نقرةٌ ثانية) يُعاد بصمت كما كان.
     */
    public function cancel(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        if (MeetingStatus::isFinalValue($meeting->status)) {
            return back();
        }

        Workflow::run(new CancelMeeting, $meeting, $request->user(), ['via' => MeetingCancelled::VIA_MEETING]);

        if ($meeting->user_id) {
            Notify::send($meeting->user_id, 'info', 't-red', "أُلغي اجتماع «{$meeting->title}».");
        }
        $this->mailMeetingEvent($meeting, 'cancelled');

        return back()->with('flash', 'أُلغي الاجتماع وحُذفت غرفته على Zoom.');
    }

    /** بريد حدث الاجتماع (إعادة جدولة/إلغاء) للعميل والمحامي — best-effort عبر MailService. */
    private function mailMeetingEvent(Meeting $meeting, string $event, ?string $reason = null): void
    {
        $mail = app(MailService::class);
        if ($meeting->user) {
            $mail->send($meeting->user, new MeetingEventMail($meeting, $event, $reason));
        }
        if ($meeting->assignedLawyer) {
            $mail->send($meeting->assignedLawyer, new MeetingEventMail($meeting, $event, $reason));
        }
    }

    // تحويل قرارات الاجتماع إلى مهام حقيقية (موديل Task) — لمرة واحدة
    public function createTasks(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        abort_if($meeting->tasks_created, 409, 'أُنشئت مهامّ هذا الاجتماع من قبل.');

        // الاحتياط: أوّل محامٍ في قائمة المشاركين، وإلا الفاعل (المحامي المسنَد له الأولوية داخل DecisionTasks)
        $fallback = self::firstNamedLawyer($meeting->participants) ?? $request->user();
        $count = DecisionTasks::create($meeting, $this->ai, $fallback);
        abort_if($count === 0, 422, 'لم تُنشأ مهامّ: لا قرارات في ملخّص الاجتماع، أو لا محامي تُسند إليه.');

        return back()->with('flash', 'تم تحويل '.$count.' قرار إلى مهام');
    }

    /** أوّل محامٍ يُذكر اسمه في قائمة المشاركين النصّية (مطابقة بالاسم). */
    private static function firstNamedLawyer(?string $participants): ?User
    {
        if (! $participants) {
            return null;
        }
        foreach (User::where('role', Role::Lawyer)->pluck('name', 'id') as $id => $name) {
            if (mb_strpos($participants, $name) !== false) {
                return User::find($id);
            }
        }

        return null;
    }

    // سجل الاجتماعات المنتهية (يطابق meetLogView)
    public function log(Request $request): Response
    {
        return Inertia::render('admin/meetlog', ['meetings' => $this->cards($request)]);
    }

    // تقارير الاجتماعات (يطابق meetReportsView) — مع تحليلات حقيقية من قاعدة البيانات
    public function reports(Request $request): Response
    {
        return Inertia::render('admin/meetreports', [
            'meetings' => $this->cards($request),
            'analytics' => $this->analytics($request),
        ]);
    }

    /**
     * تحليلات إدارية حقيقية شاملا بيانات Zoom وقاعدة البيانات:
     * اتجاه شهري، ساعات المكالمات، تغطية الذكاء الاصطناعي، التسجيلات، الاستجابة والتنفيذ.
     */
    private function analytics(Request $request): array
    {
        // 1. الاتجاه الشهري والساعات الإجمالية لآخر 6 أشهر
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = now()->startOfMonth()->subMonths($i);
            $end = (clone $start)->endOfMonth();
            $count = $this->scopedQuery($request)
                ->whereRaw('COALESCE(starts_at, created_at) BETWEEN ? AND ?', [$start, $end])
                ->count();
            $sec = (float) $this->scopedQuery($request)
                ->whereRaw('COALESCE(starts_at, created_at) BETWEEN ? AND ?', [$start, $end])
                ->sum('duration_sec');

            $months[] = [
                'm' => $start->locale('ar')->translatedFormat('M'),
                'v' => $count,
                'hours' => round($sec / 3600, 1),
            ];
        }

        // 2. توزيع الحالات — **بالحالة المشتقّة لا المخزّنة**.
        // كل بطاقة على الشاشة تعرض liveState()، والعدّ كان على العمود المخزّن: و«لم ينعقد»
        // لا يكتبها إلا أمرٌ مجدول. فكانت الشاشة تعرض اجتماعات «لم ينعقد» وعدّادها صفر،
        // و«قادم» منتفخاً بما فات موعده. الجلب مرّة واحدة يُسقط ستّ استعلامات أيضاً.
        $scoped = $this->scopedQuery($request)->get();
        $liveOf = $scoped->mapWithKeys(fn (Meeting $m) => [$m->id => $m->liveState()[1]]);

        $statusCounts = [
            'منتهٍ' => 0,
            'قادم' => 0,
            'جارٍ' => 0,
            // «بانتظار التأكيد» عُلّق: حالة يتيمة منذ إلغاء تأكيد العميل — عدّاد كان يُرجع صفراً في كل تحميل
            'لم ينعقد' => 0,
            'ملغى' => 0,
            'مؤجل' => 0,
        ];
        foreach ($liveOf as $live) {
            if (array_key_exists($live, $statusCounts)) {
                $statusCounts[$live]++;
            }
        }

        // 3. تغطية Zoom والذكاء الاصطناعي للمنتهية — على المجموعة المشتقّة نفسها
        $endedIds = $liveOf->filter(fn ($live) => $live === MeetingStatus::Ended->value)->keys()->all();
        $totalEnded = count($endedIds);
        $ended = fn () => $this->scopedQuery($request)->whereIn('id', $endedIds ?: [0]);

        $aiSummaryCount = $ended()->whereNotNull('zoom_summary_at')->count();
        $recordingCount = $ended()->where(fn ($q) => $q->whereNotNull('recording_url')->orWhereNotNull('zoom_share_url'))->count();
        $audioCount = $ended()->whereNotNull('zoom_audio_url')->count();

        // null = لا مقياس (لا اجتماع منتهياً بعد) — الصفر يدّعي تغطيةً معدومة وقد قيست
        $aiRate = $totalEnded > 0 ? (int) round($aiSummaryCount / $totalEnded * 100) : null;
        $recRate = $totalEnded > 0 ? (int) round($recordingCount / $totalEnded * 100) : null;

        // 4. الساعات الإجمالية لجميع المكالمات والاجتماعات المنتهية
        $totalDurationSec = (float) $ended()->sum('duration_sec');
        $totalZoomHours = round($totalDurationSec / 3600, 1);

        // 5. متوسط مدة الحضور الفعلية (دقائق) حسب المحامي
        $byLawyer = $ended()
            ->whereNotNull('duration_sec')->whereNotNull('assigned_lawyer_id')
            ->selectRaw('assigned_lawyer_id, AVG(duration_sec) as avg_sec')
            ->groupBy('assigned_lawyer_id')->get();
        $names = User::whereIn('id', $byLawyer->pluck('assigned_lawyer_id'))->pluck('name', 'id');
        $attendanceByLawyer = $byLawyer
            ->map(fn ($r) => [(string) ($names[$r->assigned_lawyer_id] ?? '—'), (int) round(((float) $r->avg_sec) / 60)])
            ->values()->all();

        $avgSec = (float) $ended()->whereNotNull('duration_sec')->avg('duration_sec');

        // 6. معدل تنفيذ القرارات وتحويلها لمهام
        $refs = $this->scopedQuery($request)->pluck('ref')->all();
        $totalTasks = $refs !== [] ? Task::whereIn('ref', $refs)->count() : 0;
        $doneTasks = $refs !== [] ? Task::whereIn('ref', $refs)->where('status', 'منجزة')->count() : 0;

        return [
            'monthlyTrend' => $months,
            'statusCounts' => $statusCounts,
            'attendanceByLawyer' => $attendanceByLawyer,
            // null = لم يُقَس (لا مدد فعلية / لا مهامّ) — لا صفراً يُقرأ إخفاقاً
            'avgActualMinutes' => $avgSec ? (int) round($avgSec / 60) : null,
            'decisionRate' => $totalTasks > 0 ? (int) round($doneTasks / $totalTasks * 100) : null,
            'tasksFromDecisions' => $totalTasks,
            'doneTasksCount' => $doneTasks,
            'totalZoomHours' => $totalZoomHours,
            'aiSummaryCount' => $aiSummaryCount,
            'aiCoverageRate' => $aiRate,
            'recordingCount' => $recordingCount,
            'recordingCoverageRate' => $recRate,
            'audioCount' => $audioCount,
        ];
    }

    /**
     * استعلام الاجتماعات معزولًا بالدور:
     * المحامي اجتماعاته المسندة، والموظف والإدارة كل اجتماعات المكتب.
     */
    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $query = Meeting::query();
        if ($user->role === Role::Lawyer) {
            $query->visibleToLawyer($user->id);
        }

        return $query;
    }

    // بطاقات الاجتماعات معزولة بالدور (تكشف hostLink/الملخص/المحضر — لا تُبثّ للكل)
    private function cards(Request $request)
    {
        return $this->scopedQuery($request)->with(['assignedLawyer', 'participantUsers'])
            ->latest('id')->get()->map(fn (Meeting $m) => $m->toFullCard());
    }

    /**
     * حارس الوصول المباشر لاجتماع (يسدّ IDOR): المحامي لاجتماعه المسند فقط (guardAssigned)،
     * الموظف والإدارة على المكتب كلّه.
     */
    // تنزيل نصّ الاجتماع الكامل (المحلي إن وُجد وإلا يُجلب من سحابة Zoom ويُحفظ) — معزول بالدور
    public function transcript(Request $request, Meeting $meeting): StreamedResponse
    {
        $this->guardMeetingView($request, $meeting);
        RecordingArchive::guardViewer($request->user());

        return RecordingArchive::transcript($meeting);
    }

    // فيديو جلسة الاجتماع مضغوطاً ZIP (جلب خادمي من سحابة Zoom) — معزول بالدور
    public function recordingZip(Request $request, Meeting $meeting): StreamedResponse|RedirectResponse
    {
        $this->guardMeetingView($request, $meeting);
        RecordingArchive::guardViewer($request->user());

        return RecordingArchive::download($meeting, 'video');
    }

    // صوت جلسة الاجتماع (M4A) مضغوطاً ZIP — معزول بالدور
    public function audioZip(Request $request, Meeting $meeting): StreamedResponse|RedirectResponse
    {
        $this->guardMeetingView($request, $meeting);
        RecordingArchive::guardViewer($request->user());

        return RecordingArchive::download($meeting, 'audio');
    }

    // تشغيل فيديو الاجتماع أو صوته داخل الصفحة من الملفّ المحفوظ — بديلُ فتح سحابة Zoom خارج النظام
    public function stream(Request $request, Meeting $meeting, string $type): BinaryFileResponse
    {
        $this->guardMeetingView($request, $meeting);
        RecordingArchive::guardViewer($request->user());

        return RecordingArchive::stream($meeting, $type);
    }

    /** أفعال الإدارة على الاجتماع (المحضر، البدء، الإنهاء، إعادة الجدولة، الإلغاء…) — للمسؤول وحده من المحامين. */
    private function guardMeeting(Request $request, Meeting $meeting): void
    {
        if ($request->user()->role === Role::Lawyer) {
            $this->guardAssigned($meeting);
        }
    }

    /** الاطّلاع والدخول (الصفحة، الغرفة، التسجيل والنصّ) — للمسؤول وللمشارك (`Meeting::involves`). */
    private function guardMeetingView(Request $request, Meeting $meeting): void
    {
        if ($request->user()->role === Role::Lawyer) {
            abort_unless($meeting->involves($request->user()), 403);
        }
    }

    private function prefix(Request $request): string
    {
        return match ($request->user()->role) {
            Role::Employee => 'employee',
            Role::Lawyer => 'lawyer',
            default => 'admin',
        };
    }
}
