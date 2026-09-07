<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Role;
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
use App\Services\GoogleCalendarService;
use App\Services\LegalAiService;
use App\Services\MailService;
use App\Services\ZoomService;
use App\Support\Audit;
use App\Support\Booking\BookingMoment;
use App\Support\Booking\BookingMoved;
use App\Support\ClientDirectory;
use App\Support\DecisionTasks;
use App\Support\Live;
use App\Support\MeetingSummary;
use App\Support\Notify;
use App\Support\RecordingArchive;
use App\Support\ReferenceNumber;
use App\Support\ZoomSummaryText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
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
        $this->guardMeeting($request, $meeting);

        return Inertia::render($this->prefix($request).'/meeting', [
            'meeting' => $meeting->toFullCard(),
        ]);
    }

    // غرفة الاجتماع المضمّنة (Zoom Web SDK) — ?ref=M-…
    public function room(Request $request): Response
    {
        $meeting = Meeting::where('ref', (string) $request->query('ref'))->firstOrFail();
        $this->guardMeeting($request, $meeting);
        // الحالة المشتقّة لا المخزّنة: «قادم» الفائت يُصَدّ كما يُصَدّ المنتهي — وإخفاء
        // الزرّ في الواجهة وحده يُلتفّ عليه بالرابط المباشر.
        abort_if(in_array($meeting->liveState()[1], ['منتهٍ', 'ملغى', 'لم ينعقد'], true), 422, 'انتهت هذه الجلسة أو فات موعدها — لا يمكن دخول غرفتها.');

        return Inertia::render($this->prefix($request).'/meetingroom', [
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
            'lawyers' => User::where('role', Role::Lawyer)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'staff' => User::whereIn('role', [Role::Lawyer, Role::Employee, Role::Admin])
                ->orderBy('name')->get(['id', 'name', 'role', 'department'])
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'role' => $u->role->value ?? 'كادر',
                    'label' => "{$u->name} (".($u->department ?: ($u->role->value ?? 'كادر')).')',
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

        $durations = Meeting::where('status', 'منتهٍ')->whereNotNull('duration_sec')->pluck('duration_sec');

        return [
            // null = لا مقياس بعد (لا مهامّ / لا مدد فعلية) — الصفر يدّعي قياساً وقع
            'decisionRate' => $totalTasks > 0 ? (int) round($doneTasks / $totalTasks * 100) : null,
            'avgMinutes' => $durations->count() ? (int) round(((float) $durations->avg()) / 60) : null,
        ];
    }

    // إنشاء اجتماع جديد (يطابق submitMeeting) — مع جلسة Zoom ودعوة العميل إن رُبط
    public function store(Request $request): RedirectResponse
    {
        // **التاريخ مطلوب.** كان `day`/`time` سلسلتين حرّتين اختياريّتين،
        // وحين تُتركان يُخترَع «اليوم · 10:00» — اجتماعٌ بموعدٍ لم يختره أحد.
        $data = $request->validate(BookingMoment::rules() + [
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', 'max:60'],
            'priority' => ['nullable', 'string', 'in:عالية,متوسطة,عادية'],
            'conf' => ['nullable', 'string', 'in:سري,عادي'],
            'dur' => ['nullable', 'string', 'max:30'],
            'participants' => ['nullable', 'string', 'max:300'],
            'client_id' => ['nullable', 'integer', 'exists:users,id'],
            'case_ref' => ['nullable', 'string', 'max:120'],
            'lawyer_id' => ['nullable', 'integer', new ActiveLawyer],
        ]);

        $client = ! empty($data['client_id']) ? User::find($data['client_id']) : null;
        // المحامي المسؤول: المختار صراحةً (يراه في قائمته)، وإلا المنشئ إن كان محامياً
        $assignedLawyer = ! empty($data['lawyer_id'])
            ? User::where('role', Role::Lawyer)->find((int) $data['lawyer_id'])
            : ($request->user()->role === Role::Lawyer ? $request->user() : null);
        $durMinutes = (int) ($data['dur'] ?? 60) ?: 60;
        // اللحظة مصدر الوقت **والعرض** معاً — فلا يقول `when_label` شيئاً
        // و`starts_at` شيئاً آخر (أو لا يقول شيئاً).
        $moment = BookingMoment::from($data['day'], $data['time'], $durMinutes);
        $startsAt = $moment->startsAt;
        $when = $moment->label();

        $zoom = $this->zoom->createMeeting($data['title'], $durMinutes, ($data['conf'] ?? '') === 'سري', $startsAt);

        // لا 'بانتظار التأكيد': تأكيد العميل أُلغي، فالاجتماع مجدول منذ إنشائه
        $status = 'قادم';

        $meeting = Meeting::create([
            'user_id' => $client?->id,
            'ref' => 'M-'.now()->format('y').random_int(100, 999),
            'title' => $data['title'],
            'type' => $data['type'],
            'client_name' => $client?->name ?: 'داخلي',
            'when_label' => $when,
            'starts_at' => $startsAt,
            'status' => $status,
            'priority' => $data['priority'] ?? 'عادية',
            'conf' => $data['conf'] ?? 'عادي',
            'dur' => ($data['dur'] ?? '') ?: '60 دقيقة',
            'participants' => ($data['participants'] ?? '') ?: null,
            'case_ref' => ($data['case_ref'] ?? '') ?: null,
            'meet_id' => $zoom['id'] ?? null,
            'meet_link' => $zoom['join_url'] ?? null,
            'host_link' => $zoom['start_url'] ?? null,
            'meet_password' => $zoom['password'] ?? null,
            'created_by' => $request->user()->name,
            // عزل الرؤية/البثّ: المحامي المسؤول (المحامي يرى المسنَد إليه وحده)
            'assigned_lawyer_id' => $assignedLawyer?->id,
            // عُلّق بطلب صاحب المنتج (2026-08-26): قوائم «قبل/أثناء/بعد الاجتماع» نصّ ثابت مختلق لا بيانات حقيقية
            // 'before_items' => ['تحليل الموضوع', 'مراجعة المستندات', 'تجهيز جدول الأعمال'],
            // 'during_items' => ['تحويل الصوت إلى نص', 'استخراج القرارات', 'تحديد المهام'],
            // 'after_items' => ['إنشاء الملخص', 'تحديث القضية', 'إنشاء المهام'],
            'has_link' => true,
        ]);

        // إشعار (داخل التطبيق + بريد) بموعد الاجتماع — توجيه آمن داخل المنصّة لكل دور (لا روابط خارجية)
        if ($client) {
            // إنشاء سجل دعوة رسمي مؤكَّد يتيح للعميل مراجعة تفاصيل الاجتماع (تأكيد الحضور مُلغى)
            $meetRequest = MeetRequest::create([
                'user_id' => $client->id,
                'meeting_id' => $meeting->id,
                'ref' => ReferenceNumber::next(MeetRequest::class, 'ref', 'MR'),
                'service' => $data['title'],
                'type' => $data['type'],
                'case_ref' => ($data['case_ref'] ?? '') ?: null,
                'day' => $data['day'] ?: now()->format('Y-m-d'),
                'time' => $data['time'] ?: '10:00',
                'duration_min' => $durMinutes,
                'assigned_lawyer_id' => $assignedLawyer?->id,
                'sent_by' => $request->user()->name.' ('.$request->user()->role->label().')',
                'sent_by_id' => $request->user()->id,
                'stage' => MeetRequest::STAGE_CONFIRMED, // مؤكَّدة منذ الإرسال (لا تأكيد من العميل)
                'meet_id' => $zoom['id'] ?? null,
                'meet_link' => $zoom['join_url'] ?? null,
                'host_link' => $zoom['start_url'] ?? null,
            ]);

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
        $participantNames = preg_split('/[،,]/u', (string) ($data['participants'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_filter(array_map('trim', $participantNames)) as $pName) {
            // الواجهة ترسل «الاسم (القسم/الدور)» — تُزال اللاحقة القوسية للمطابقة بالاسم المجرّد
            $pName = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $pName));
            $staffUser = User::where('name', $pName)->first();
            if ($staffUser && $staffUser->id !== $assignedLawyer?->id) {
                $staffUrl = $meeting->portalUrlFor($staffUser);
                Notify::send($staffUser->id, 'video', 't-blue', "تمت إضافتك كمشارك في اجتماع «{$meeting->title}» — {$when}. متاح في لوحتك.");
                app(MailService::class)->send($staffUser, new MeetingScheduledMail(
                    $staffUser->name,
                    $meeting->title,
                    $when,
                    $staffUrl,
                    'داخل النظام الإداري لمكاتب المحاماة',
                    'تمت إضافتك كمشارك في الاجتماع من قِبل الإدارة، يرجى تسجيل الدخول للمنصة للحضور.'
                ));
            }
        }

        GoogleCalendarService::syncMeeting($meeting);

        return back();
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
        if ($meeting->approve !== 'معتمد') {
            // الاعتماد بعد اكتمال الجلسة وتوفّر مخرجات **حقيقية** فقط (ملخص Zoom أو تدوين يدوي) —
            // النصوص القالبية («بانتظار ملخص الجلسة من Zoom») مملوءة تقنياً لكنها ليست مخرجات؛
            // اعتمادها كان يعرض للعميل محضراً رسمياً بلا مضمون (حادثة M-26753).
            $hasRealOutput = (filled($meeting->summary) && ! ZoomSummaryText::isPlaceholderSummary($meeting->summary))
                || (filled($meeting->minutes) && ! ZoomSummaryText::isPlaceholderMinutes($meeting->minutes));
            abort_unless(
                $meeting->status === 'منتهٍ' && $hasRealOutput,
                422,
                'الاعتماد متاح بعد انتهاء الاجتماع ووصول ملخص Zoom أو تدوين المحضر يدوياً.'
            );
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

        if ($meeting->status !== 'منتهٍ') {
            // مزامنة Zoom: إنهاء الجلسة الجارية فعليًا على Zoom (best-effort) — الويبهوك اللاحق يُمتَصّ بحارس «منتهٍ»
            $this->zoom->endMeeting((string) $meeting->meet_id);
            $meeting->update([
                'status' => 'منتهٍ',
                // المدخل اليدوي يُحترم؛ وإلا 0 = غير مسجَّلة (كانت 90 مختلقة تُعرض كنسبة حقيقية)
                'attend' => $data['attend'] ?? $meeting->attend ?: 0,
            ]);
            MeetRequest::where('meeting_id', $meeting->id)
                ->where('stage', '<', MeetRequest::STAGE_EXECUTED)
                ->update(['stage' => MeetRequest::STAGE_EXECUTED]);
            Live::push(new MeetingStatusBroadcast($meeting));

            $notes = trim($data['notes'] ?? '');
            GenerateMeetingSummaryJob::dispatch($meeting, $notes);
        }

        return back();
    }

    // بدء الاجتماع يدويًا (قادم/مؤجل → جارٍ) — للاجتماعات المجدولة مباشرةً بلا دعوة
    public function start(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        if (in_array($meeting->status, ['قادم', 'مؤجل'], true)) {
            $meeting->update(['status' => 'جارٍ']);
            MeetRequest::where('meeting_id', $meeting->id)
                ->where('stage', '<', MeetRequest::STAGE_EXECUTED)
                ->update(['stage' => MeetRequest::STAGE_EXECUTED]);
            if ($meeting->user_id) {
                Notify::send($meeting->user_id, 'video', 't-cyan', "بدأت جلسة اجتماع «{$meeting->title}» — يمكنك الدخول الآن.");
            }
            Live::push(new MeetingStatusBroadcast($meeting));
        }

        return back();
    }

    // إعادة جدولة/تأجيل الاجتماع — يزامن الموعد مع Zoom (PATCH) ويعيد تسليح التذكير
    public function reschedule(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        // حارس خادمي: النهائية لا تُعاد جدولتها (إخفاء الزر في الواجهة وحده يُلتفّ عليه)
        abort_if(in_array($meeting->status, ['منتهٍ', 'ملغى'], true), 422, 'الاجتماع منتهٍ أو ملغى — أنشئ اجتماعاً جديداً بدل إعادة جدولته.');
        // **موعدٌ مفهوم لا سلسلة حرّة.** كانت إعادة الجدولة تقبل أيّ نصّ،
        // فتُنتج `starts_at = null` وحالةً «مؤجّل» — واجتماعٌ بلا طابع زمنيّ
        // لا يصله تذكيرٌ أبداً (`meetings:send-reminders` يشترطه).
        // **و«أُجِّل بلا موعد» نيّةٌ صريحة لا حصيلةُ نصٍّ لا يُفكّ.** كانت الحالة
        // «مؤجل» تُبلَغ بكتابة أيّ نصٍّ غير مفهوم في حقل التاريخ، فيقع التأجيل
        // بالمصادفة؛ وتشديدُ الصيغة وحده كان سيجعلها **غير قابلة للبلوغ** رغم أن
        // لها تبويباً وعدّاداً في اللوحة — وهو مصير «بانتظار التأكيد» المعلّق أدناه.
        $postpone = $request->boolean('postpone');
        $data = $request->validate($postpone ? [] : BookingMoment::rules());

        $moment = $postpone ? null : BookingMoment::from($data['day'], $data['time'], $meeting->durationMinutes() ?: 60);
        $startsAt = $moment?->startsAt;
        $when = $moment?->label() ?? 'يُحدَّد لاحقاً';

        $meeting->update([
            'status' => $startsAt ? 'قادم' : 'مؤجل',
            'when_label' => $when,
            'starts_at' => $startsAt,
            'reminder_sent_at' => null, // إعادة تسليح التذكير للموعد الجديد
            // بيانات جلسة Zoom القديمة لم تعد تخص الموعد الجديد — كانت تلوّث liveState (فات ودخل أحد ⇒ منتهٍ)
            'join_time' => null,
            'leave_time' => null,
            'duration_sec' => null,
            'recording_url' => null,
            'attend' => 0, // العمود غير قابل لـnull — صفر يعني «لم يُسجَّل حضور بعد»
        ]);

        // **Zoom يتبع الموعد بمدّته الحقيقيّة.** كانت المزامنة تُرسل `duration => 60`
        // مثبَّتة، فاجتماعٌ مدّته ٩٠ أو ١٢٠ يُخفَّض إلى ٦٠ على Zoom في كلّ إعادة جدولة
        // بينما `Meeting::durationMinutes()` ما زال يقول ٩٠ — فتتباعد الجهتان صامتتين.
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
        if ($meeting->user_id) {
            Notify::send($meeting->user_id, 'cal', 't-amber', "أُعيدت جدولة اجتماع «{$meeting->title}»: {$when}.");
        }
        $this->mailMeetingEvent($meeting, 'rescheduled');
        // تحديث حدث تقويم Google بالموعد الجديد — بدونه يبقى التقويم على الموعد القديم
        GoogleCalendarService::syncMeeting($meeting->refresh());
        Live::push(new MeetingStatusBroadcast($meeting));

        return back();
    }

    // إلغاء الاجتماع — يحذف اجتماع Zoom ويضبط «ملغى» (حالة نهائية يحميها حارس الويبهوك)
    public function cancel(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        if (! in_array($meeting->status, ['منتهٍ', 'ملغى'], true)) {
            $this->zoom->deleteMeeting((string) $meeting->meet_id);
            $meeting->update(['status' => 'ملغى', 'meet_id' => null]);
            // سجلّ تاريخي «أُلغيت» بدل الحذف الصلب — كان أثر الدعوة يختفي من شاشة العميل بلا تفسير.
            // الدعوة المعتمدة (STAGE_APPROVED) تُستثنى: تنزيلها يمحو سجلّ اعتمادها بلا رجعة
            // لأن resend لا يقبل إلا «منتهية الصلاحية» — فتصير بلا مخرج.
            MeetRequest::where('meeting_id', $meeting->id)
                ->where('stage', '!=', MeetRequest::STAGE_APPROVED)
                ->update(['stage' => MeetRequest::STAGE_CANCELLED]);
            if ($meeting->user_id) {
                Notify::send($meeting->user_id, 'info', 't-red', "أُلغي اجتماع «{$meeting->title}».");
            }
            $this->mailMeetingEvent($meeting, 'cancelled');
            GoogleCalendarService::deleteMeetingEvent($meeting);
            Audit::log(
                action: 'إلغاء اجتماع',
                description: "ألغى {$request->user()->name} الاجتماع «{$meeting->title}» ({$meeting->ref}).",
                category: 'اجتماعات',
                severity: 'warning',
                auditable: $meeting,
            );
            Live::push(new MeetingStatusBroadcast($meeting));
        }

        return back();
    }

    /** بريد حدث الاجتماع (إعادة جدولة/إلغاء) للعميل والمحامي — best-effort عبر MailService. */
    private function mailMeetingEvent(Meeting $meeting, string $event): void
    {
        $mail = app(MailService::class);
        if ($meeting->user) {
            $mail->send($meeting->user, new MeetingEventMail($meeting, $event));
        }
        if ($meeting->assignedLawyer) {
            $mail->send($meeting->assignedLawyer, new MeetingEventMail($meeting, $event));
        }
    }

    // تحويل قرارات الاجتماع إلى مهام حقيقية (موديل Task) — لمرة واحدة
    public function createTasks(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        abort_if($meeting->tasks_created, 409);

        // الاحتياط: أوّل محامٍ في قائمة المشاركين، وإلا الفاعل (المحامي المسنَد له الأولوية داخل DecisionTasks)
        $fallback = self::firstNamedLawyer($meeting->participants) ?? $request->user();
        $count = DecisionTasks::create($meeting, $this->ai, $fallback);
        abort_if($count === 0, 422);

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
        $endedIds = $liveOf->filter(fn ($live) => $live === 'منتهٍ')->keys()->all();
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
            $query->where('assigned_lawyer_id', $user->id);
        }

        return $query;
    }

    // بطاقات الاجتماعات معزولة بالدور (تكشف hostLink/الملخص/المحضر — لا تُبثّ للكل)
    private function cards(Request $request)
    {
        return $this->scopedQuery($request)->with('assignedLawyer')
            ->latest('id')->get()->map(fn (Meeting $m) => $m->toFullCard());
    }

    /**
     * حارس الوصول المباشر لاجتماع (يسدّ IDOR): المحامي لاجتماعه المسند فقط (guardAssigned)،
     * الموظف والإدارة على المكتب كلّه.
     */
    // تنزيل نصّ الاجتماع الكامل (المحلي إن وُجد وإلا يُجلب من سحابة Zoom ويُحفظ) — معزول بالدور
    public function transcript(Request $request, Meeting $meeting): StreamedResponse
    {
        $this->guardMeeting($request, $meeting);

        return RecordingArchive::transcript($meeting);
    }

    // فيديو جلسة الاجتماع مضغوطاً ZIP (جلب خادمي من سحابة Zoom) — معزول بالدور
    public function recordingZip(Request $request, Meeting $meeting): StreamedResponse|RedirectResponse
    {
        $this->guardMeeting($request, $meeting);

        return RecordingArchive::download($meeting, 'video');
    }

    // صوت جلسة الاجتماع (M4A) مضغوطاً ZIP — معزول بالدور
    public function audioZip(Request $request, Meeting $meeting): StreamedResponse|RedirectResponse
    {
        $this->guardMeeting($request, $meeting);

        return RecordingArchive::download($meeting, 'audio');
    }

    private function guardMeeting(Request $request, Meeting $meeting): void
    {
        if ($request->user()->role === Role::Lawyer) {
            $this->guardAssigned($meeting);
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
