<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Role;
use App\Events\MeetingStatusBroadcast;
use App\Http\Controllers\Concerns\ScopedToLawyer;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateMeetingSummaryJob;
use App\Mail\MeetingEndedMail;
use App\Mail\MeetingScheduledMail;
use App\Models\Meeting;
use App\Models\MeetRequest;
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
use Inertia\Inertia;
use Inertia\Response;

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
        ]);
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

        $zoom = $this->zoom->createMeeting($data['title'], 60, ($data['conf'] ?? '') === 'سري');

        $meeting = Meeting::create([
            'user_id' => $client?->id,
            'ref' => 'M-'.now()->format('y').random_int(100, 999),
            'title' => $data['title'],
            'type' => $data['type'],
            'client_name' => $client?->name ?: 'داخلي',
            'when_label' => $when,
            'starts_at' => MeetingTime::parse($data['day'] ?? null, $data['time'] ?? null),
            'status' => 'قادم',
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

        // إشعار (داخل التطبيق + بريد) بموعد الاجتماع — للعميل والمحامي المسؤول
        $link = $meeting->joinLink();
        if ($client) {
            Notify::send($client->id, 'video', 't-blue', "تمت جدولة اجتماع «{$meeting->title}» — {$when}. الرابط متاح في صفحة الاجتماعات.");
            app(MailService::class)->send($client, new MeetingScheduledMail($client->name, $meeting->title, $when, $link));
        }
        if ($assignedLawyer) {
            app(MailService::class)->send($assignedLawyer, new MeetingScheduledMail($assignedLawyer->name, $meeting->title, $when, $link));
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

    // تقارير الاجتماعات (يطابق meetReportsView)
    public function reports(Request $request): Response
    {
        return Inertia::render('admin/meetreports', ['meetings' => $this->cards($request)]);
    }

    /**
     * بطاقات الاجتماعات معزولة بالدور (تكشف hostLink/الملخص/المحضر — لا تُبثّ للكل):
     * المحامي اجتماعاته المسندة، الموظف اجتماعات فرعه (+بلا فرع)، الإدارة الكل.
     */
    private function cards(Request $request)
    {
        $user = $request->user();
        $query = Meeting::query();
        if ($user->role === Role::Lawyer) {
            $query->where('assigned_lawyer_id', $user->id);
        } elseif ($user->role === Role::Employee) {
            $branch = $user->branch;
            $query->where(fn ($q) => $q->where('branch', $branch)->orWhereNull('branch'));
        }

        return $query->latest('id')->get()->map(fn (Meeting $m) => $m->toFullCard());
    }

    /**
     * حارس الوصول المباشر لاجتماع (يسدّ IDOR): المحامي لاجتماعه المسند فقط (guardAssigned)،
     * الموظف لفرعه (بلا فرع = مشترك)، الإدارة كاملة.
     */
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
