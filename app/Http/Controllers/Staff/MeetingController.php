<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Role;
use App\Events\MeetingStatusBroadcast;
use App\Http\Controllers\Concerns\ScopedToLawyer;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateMeetingSummaryJob;
use App\Mail\MeetInviteMail;
use App\Mail\MeetingEndedMail;
use App\Mail\MeetingEventMail;
use App\Mail\MeetingScheduledMail;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\Task;
use App\Models\User;
use App\Rules\LawyerInBranch;
use App\Services\LegalAiService;
use App\Services\MailService;
use App\Services\ZoomService;
use App\Support\ClientDirectory;
use App\Support\DecisionTasks;
use App\Support\Live;
use App\Support\MeetingTime;
use App\Support\Notify;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
            'decisionRate' => $totalTasks > 0 ? (int) round($doneTasks / $totalTasks * 100) : 0,
            'avgMinutes' => $durations->count() ? (int) round(((float) $durations->avg()) / 60) : 0,
        ];
    }

    // إنشاء اجتماع جديد (يطابق submitMeeting) — مع جلسة Zoom ودعوة العميل إن رُبط
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', 'max:60'],
            'priority' => ['nullable', 'string', 'in:عالية,متوسطة,عادية'],
            'conf' => ['nullable', 'string', 'in:سري,عادي'],
            'dur' => ['nullable', 'string', 'max:30'],
            'participants' => ['nullable', 'string', 'max:300'],
            'day' => ['nullable', 'string', 'max:40'],
            'time' => ['nullable', 'string', 'max:20'],
            'client_id' => ['nullable', 'integer', 'exists:users,id'],
            'case_ref' => ['nullable', 'string', 'max:120'],
            'lawyer_id' => ['nullable', 'integer', new LawyerInBranch],
        ]);

        $client = ! empty($data['client_id']) ? User::find($data['client_id']) : null;
        // المحامي المسؤول: المختار صراحةً (يراه في قائمته)، وإلا المنشئ إن كان محامياً
        $assignedLawyer = ! empty($data['lawyer_id'])
            ? User::where('role', Role::Lawyer)->find((int) $data['lawyer_id'])
            : ($request->user()->role === Role::Lawyer ? $request->user() : null);
        $when = trim(($data['day'] ?? '') !== '' ? $data['day'].' · '.($data['time'] ?? '') : 'اليوم · 10:00');
        $startsAt = MeetingTime::parse($data['day'] ?? null, $data['time'] ?? null);
        $durMinutes = (int) ($data['dur'] ?? 60) ?: 60;

        $zoom = $this->zoom->createMeeting($data['title'], $durMinutes, ($data['conf'] ?? '') === 'سري', $startsAt);

        $status = $client ? 'بانتظار التأكيد' : 'قادم';

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
            // عزل الرؤية/البثّ: المحامي المسؤول وفرعه (وإلا فرع المنشئ)
            'branch' => $assignedLawyer?->branch ?: $request->user()->branch,
            'assigned_lawyer_id' => $assignedLawyer?->id,
            'before_items' => ['تحليل الموضوع', 'مراجعة المستندات', 'تجهيز جدول الأعمال'],
            'during_items' => ['تحويل الصوت إلى نص', 'استخراج القرارات', 'تحديد المهام'],
            'after_items' => ['إنشاء الملخص', 'تحديث القضية', 'إنشاء المهام'],
            'is_up' => true,
            'has_link' => true,
        ]);

        // إشعار (داخل التطبيق + بريد) بموعد الاجتماع — توجيه آمن داخل المنصّة لكل دور (لا روابط خارجية)
        if ($client) {
            // إنشاء سجل دعوة رسمي يتيح للعميل مراجعة الدعوة وتأكيد الحضور
            $meetRequest = MeetRequest::create([
                'user_id' => $client->id,
                'meeting_id' => $meeting->id,
                'ref' => 'MR-'.random_int(1000, 9999),
                'service' => $data['title'],
                'type' => $data['type'],
                'case_ref' => ($data['case_ref'] ?? '') ?: null,
                'day' => $data['day'] ?: now()->format('Y-m-d'),
                'time' => $data['time'] ?: '10:00',
                'duration_min' => $durMinutes,
                'assigned_lawyer_id' => $assignedLawyer?->id,
                'sent_by' => $request->user()->name.' ('.$request->user()->role->label().')',
                'sent_by_id' => $request->user()->id,
                'stage' => MeetRequest::STAGE_SENT, // 0 = بانتظار تأكيد العميل
                'meet_id' => $zoom['id'] ?? null,
                'meet_link' => $zoom['join_url'] ?? null,
                'host_link' => $zoom['start_url'] ?? null,
            ]);

            // إشعار وبريد دعوة رسمي للعميل ليؤكد حضوره من المنصة
            Notify::send($client->id, 'video', 't-blue', "وصلتك دعوة اجتماع «{$meeting->title}» ({$when}). يُرجى تسجيل الدخول وتأكيد الحضور من «دعوات الاجتماع».");
            app(MailService::class)->send($client, new MeetInviteMail($meetRequest));
        }

        if ($assignedLawyer) {
            $lawyerUrl = $meeting->portalUrlFor($assignedLawyer);
            $msg = $client
                ? "تمت جدولة اجتماع «{$meeting->title}» — {$when} بانتظار تأكيد حضور العميل."
                : "تم تكليفك باجتماع داخلي «{$meeting->title}» — {$when}.";
            Notify::send($assignedLawyer->id, 'video', 't-blue', $msg);
            app(MailService::class)->send($assignedLawyer, new MeetingScheduledMail(
                $assignedLawyer->name,
                $meeting->title,
                $when,
                $lawyerUrl,
                'داخل لوحة المحامي (قسم طلبات الاجتماعات)',
                $client ? 'تم إرسال الدعوة للعميل وهي بانتظار تأكيد حضوره عبر المنصة.' : 'يرجى الدخول للوحة المحامي للاطلاع على تفاصيل الجلسة.'
            ));
        }

        // إشعار باقي الكادر القانوني والإداري المشارك في الجلسة (الموظفون/المحامون)
        if (! empty($validated['participants'])) {
            foreach ($validated['participants'] as $pName) {
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
        }

        return back();
    }

    // حفظ ملخص الاجتماع (المحامي)
    public function saveSummary(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        $data = $request->validate(['summary' => ['required', 'string', 'max:6000']]);
        $meeting->update(['summary' => $data['summary']]);

        return back();
    }

    // حفظ محضر الاجتماع (المحامي)
    public function saveMinutes(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        $data = $request->validate(['minutes' => ['required', 'string', 'max:8000']]);
        $meeting->update(['minutes' => $data['minutes']]);

        return back();
    }

    // اعتماد الاجتماع ومحضره (الإدارة — يطابق mApprove) → يظهر المحضر والملخص للعميل
    public function approve(Request $request, Meeting $meeting): RedirectResponse
    {
        if ($meeting->approve !== 'معتمد') {
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
                'is_up' => false,
                'attend' => $data['attend'] ?? $meeting->attend ?: 90,
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
            $meeting->update(['status' => 'جارٍ', 'is_up' => true]);
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
        $data = $request->validate([
            'day' => ['required', 'string', 'max:40'],
            'time' => ['nullable', 'string', 'max:20'],
        ]);

        $startsAt = MeetingTime::parse($data['day'], $data['time'] ?? null);
        $when = trim($data['day'].(($data['time'] ?? '') !== '' ? ' · '.$data['time'] : ''));

        // مزامنة Zoom: تحديث موعد الاجتماع (إن كان له موعد قابل للتحليل)
        if ($startsAt) {
            $this->zoom->updateMeeting((string) $meeting->meet_id, [
                'start_time' => $startsAt->format('Y-m-d\TH:i:s'),
                'duration' => 60,
                'topic' => $meeting->title,
            ]);
        }

        $meeting->update([
            'status' => $startsAt ? 'قادم' : 'مؤجل',
            'is_up' => true,
            'when_label' => $when,
            'starts_at' => $startsAt,
            'reminder_sent_at' => null, // إعادة تسليح التذكير للموعد الجديد
        ]);
        if ($meeting->user_id) {
            Notify::send($meeting->user_id, 'cal', 't-amber', "أُعيدت جدولة اجتماع «{$meeting->title}»: {$when}.");
        }
        $this->mailMeetingEvent($meeting, 'rescheduled');
        Live::push(new MeetingStatusBroadcast($meeting));

        return back();
    }

    // إلغاء الاجتماع — يحذف اجتماع Zoom ويضبط «ملغى» (حالة نهائية يحميها حارس الويبهوك)
    public function cancel(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guardMeeting($request, $meeting);
        if (! in_array($meeting->status, ['منتهٍ', 'ملغى'], true)) {
            $this->zoom->deleteMeeting((string) $meeting->meet_id);
            $meeting->update(['status' => 'ملغى', 'is_up' => false, 'meet_id' => null]);
            MeetRequest::where('meeting_id', $meeting->id)->delete();
            if ($meeting->user_id) {
                Notify::send($meeting->user_id, 'info', 't-red', "أُلغي اجتماع «{$meeting->title}».");
            }
            $this->mailMeetingEvent($meeting, 'cancelled');
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

        // 2. توزيع الحالات
        $statusCounts = [
            'منتهٍ' => $this->scopedQuery($request)->where('status', 'منتهٍ')->count(),
            'قادم' => $this->scopedQuery($request)->where('status', 'قادم')->count(),
            'لم ينعقد' => $this->scopedQuery($request)->where('status', 'لم ينعقد')->count(),
            'ملغى' => $this->scopedQuery($request)->where('status', 'ملغى')->count(),
            'مؤجل' => $this->scopedQuery($request)->where('status', 'مؤجل')->count(),
        ];

        // 3. تغطية Zoom والذكاء الاصطناعي للمنتهية
        $totalEnded = $statusCounts['منتهٍ'];
        $aiSummaryCount = $this->scopedQuery($request)->where('status', 'منتهٍ')->whereNotNull('zoom_summary_at')->count();
        $recordingCount = $this->scopedQuery($request)->where('status', 'منتهٍ')->where(fn ($q) => $q->whereNotNull('recording_url')->orWhereNotNull('zoom_share_url'))->count();
        $audioCount = $this->scopedQuery($request)->where('status', 'منتهٍ')->whereNotNull('zoom_audio_url')->count();

        $aiRate = $totalEnded > 0 ? (int) round($aiSummaryCount / $totalEnded * 100) : 0;
        $recRate = $totalEnded > 0 ? (int) round($recordingCount / $totalEnded * 100) : 0;

        // 4. الساعات الإجمالية لجميع المكالمات والاجتماعات المنتهية
        $totalDurationSec = (float) $this->scopedQuery($request)->where('status', 'منتهٍ')->sum('duration_sec');
        $totalZoomHours = round($totalDurationSec / 3600, 1);

        // 5. متوسط مدة الحضور الفعلية (دقائق) حسب المحامي
        $byLawyer = $this->scopedQuery($request)
            ->where('status', 'منتهٍ')->whereNotNull('duration_sec')->whereNotNull('assigned_lawyer_id')
            ->selectRaw('assigned_lawyer_id, AVG(duration_sec) as avg_sec')
            ->groupBy('assigned_lawyer_id')->get();
        $names = User::whereIn('id', $byLawyer->pluck('assigned_lawyer_id'))->pluck('name', 'id');
        $attendanceByLawyer = $byLawyer
            ->map(fn ($r) => [(string) ($names[$r->assigned_lawyer_id] ?? '—'), (int) round(((float) $r->avg_sec) / 60)])
            ->values()->all();

        $avgSec = (float) $this->scopedQuery($request)->where('status', 'منتهٍ')->whereNotNull('duration_sec')->avg('duration_sec');

        // 6. معدل تنفيذ القرارات وتحويلها لمهام
        $refs = $this->scopedQuery($request)->pluck('ref')->all();
        $totalTasks = $refs !== [] ? Task::whereIn('ref', $refs)->count() : 0;
        $doneTasks = $refs !== [] ? Task::whereIn('ref', $refs)->where('status', 'منجزة')->count() : 0;

        return [
            'monthlyTrend' => $months,
            'statusCounts' => $statusCounts,
            'attendanceByLawyer' => $attendanceByLawyer,
            'avgActualMinutes' => $avgSec ? (int) round($avgSec / 60) : 0,
            'decisionRate' => $totalTasks > 0 ? (int) round($doneTasks / $totalTasks * 100) : 0,
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
     * المحامي اجتماعاته المسندة، الموظف اجتماعات فرعه (+بلا فرع)، الإدارة الكل.
     */
    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $query = Meeting::query();
        if ($user->role === Role::Lawyer) {
            $query->where('assigned_lawyer_id', $user->id);
        } elseif ($user->role === Role::Employee) {
            $query->where(fn ($q) => $q->where('branch', $user->branch)->orWhereNull('branch'));
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
     * الموظف لفرعه (بلا فرع = مشترك)، الإدارة كاملة.
     */
    // تنزيل نصّ الاجتماع الكامل المحفوظ محليًا (من تسجيل Zoom) — معزول بالدور
    public function transcript(Request $request, Meeting $meeting): StreamedResponse
    {
        $this->guardMeeting($request, $meeting);
        abort_unless($meeting->transcript_path && Storage::disk('local')->exists($meeting->transcript_path), 404);

        return Storage::disk('local')->download($meeting->transcript_path, 'transcript-'.$meeting->ref.'.txt');
    }

    private function guardMeeting(Request $request, Meeting $meeting): void
    {
        $user = $request->user();
        if ($user->role === Role::Lawyer) {
            $this->guardAssigned($meeting);
        } elseif ($user->role === Role::Employee) {
            abort_if($meeting->branch !== null && $meeting->branch !== $user->branch, 403);
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
