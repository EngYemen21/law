<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Role;
use App\Events\MeetingStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\LegalAiService;
use App\Services\ZoomService;
use App\Support\AfterResponse;
use App\Support\ClientDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * اجتماعات المكتب (يطابق lwMeetings/meetingView/meetMgmtView/adMeetings/meetLogView/meetReportsView).
 */
class MeetingController extends Controller
{
    public function __construct(private ZoomService $zoom, private LegalAiService $ai) {}

    // قائمة الاجتماعات (المحامي) / اعتماد الاجتماعات (الإدارة)
    public function index(Request $request): Response
    {
        return Inertia::render($this->prefix($request).'/meetings', [
            'meetings' => $this->cards(),
        ]);
    }

    // تفاصيل الاجتماع (يطابق meetingView) — ?id=M-…
    public function show(Request $request): Response
    {
        $meeting = Meeting::where('ref', (string) $request->query('id'))->firstOrFail();

        return Inertia::render($this->prefix($request).'/meeting', [
            'meeting' => $meeting->toFullCard(),
        ]);
    }

    // لوحة إدارة الاجتماعات (يطابق meetMgmtView)
    public function mgmt(Request $request): Response
    {
        return Inertia::render('admin/meetmgmt', [
            'meetings' => $this->cards(),
            'clients' => ClientDirectory::list(),
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
        ]);

        $client = ! empty($data['client_id']) ? User::find($data['client_id']) : null;
        $when = trim(($data['day'] ?? '') !== '' ? $data['day'].' · '.($data['time'] ?? '') : 'اليوم · 10:00');

        $zoom = $this->zoom->createMeeting($data['title'], 60, ($data['conf'] ?? '') === 'سري');

        $meeting = Meeting::create([
            'user_id' => $client?->id,
            'ref' => 'M-'.now()->format('y').random_int(100, 999),
            'title' => $data['title'],
            'type' => $data['type'],
            'client_name' => $client?->name ?: 'داخلي',
            'when_label' => $when,
            'status' => 'قادم',
            'priority' => $data['priority'] ?? 'عادية',
            'conf' => $data['conf'] ?? 'عادي',
            'dur' => ($data['dur'] ?? '') ?: '60 دقيقة',
            'participants' => ($data['participants'] ?? '') ?: null,
            'case_ref' => ($data['case_ref'] ?? '') ?: null,
            'meet_id' => $zoom['id'] ?? null,
            'meet_link' => $zoom['join_url'] ?? null,
            'host_link' => $zoom['start_url'] ?? null,
            'created_by' => $request->user()->name,
            'before_items' => ['تحليل الموضوع', 'مراجعة المستندات', 'تجهيز جدول الأعمال'],
            'during_items' => ['تحويل الصوت إلى نص', 'استخراج القرارات', 'تحديد المهام'],
            'after_items' => ['إنشاء الملخص', 'تحديث القضية', 'إنشاء المهام'],
            'is_up' => true,
            'has_link' => true,
        ]);

        if ($client) {
            UserNotification::create([
                'user_id' => $client->id,
                'icon' => 'video',
                'tone' => 't-blue',
                'body' => "تمت جدولة اجتماع «{$meeting->title}» — {$when}. الرابط متاح في صفحة الاجتماعات.",
                'time_label' => 'الآن',
                'is_read' => false,
            ]);
        }

        return back();
    }

    // حفظ ملخص الاجتماع (المحامي)
    public function saveSummary(Request $request, Meeting $meeting): RedirectResponse
    {
        $data = $request->validate(['summary' => ['required', 'string', 'max:6000']]);
        $meeting->update(['summary' => $data['summary']]);

        return back();
    }

    // حفظ محضر الاجتماع (المحامي)
    public function saveMinutes(Request $request, Meeting $meeting): RedirectResponse
    {
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

            if ($meeting->user_id) {
                UserNotification::create([
                    'user_id' => $meeting->user_id,
                    'icon' => 'doc',
                    'tone' => 't-green',
                    'body' => "اعتمدت الإدارة محضر وملخص اجتماع «{$meeting->title}» — متاحان الآن في صفحة الاجتماعات.",
                    'time_label' => 'الآن',
                    'is_read' => false,
                ]);
            }
            // بثّ الاعتماد → يصل الملخص/المحضر للعميل لحظياً
            broadcast(new MeetingStatusBroadcast($meeting->fresh()));
        }

        return back();
    }

    // إنهاء الاجتماع: تسجيل الحضور + توليد ملخص/محضر/قرارات بالذكاء الاصطناعي + بثّ لحظي
    public function end(Request $request, Meeting $meeting): RedirectResponse
    {
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
            broadcast(new MeetingStatusBroadcast($meeting));

            // توليد المخرجات بالذكاء الاصطناعي بعد إرسال الاستجابة (تفادي مهلة الويب)
            $notes = trim($data['notes'] ?? '');
            AfterResponse::defer(function () use ($meeting, $notes) {
                if (filled($meeting->summary) && filled($meeting->minutes)) {
                    return; // مخرجات محفوظة يدوياً — لا تُستبدل
                }
                $out = $this->ai->meetingSummary($meeting->fresh(), $notes);
                $meeting->update([
                    'summary' => $meeting->summary ?: $out['summary'],
                    'minutes' => $meeting->minutes ?: $out['minutes'],
                    'decisions' => $out['decisions'],
                    'has_summary' => true,
                    'has_minutes' => true,
                ]);
                broadcast(new MeetingStatusBroadcast($meeting));
            });
        }

        return back();
    }

    // تحويل قرارات الاجتماع إلى مهام حقيقية (موديل Task) — لمرة واحدة
    public function createTasks(Request $request, Meeting $meeting): RedirectResponse
    {
        abort_if($meeting->tasks_created, 409);
        $decisions = $meeting->decisions ?? [];
        abort_if(count($decisions) === 0, 422);

        // المسؤول: الفاعل إن كان محامياً، وإلا أوّل محامٍ في قائمة المشاركين، وإلا الفاعل
        $owner = $request->user()->role === Role::Lawyer
            ? $request->user()
            : (self::firstNamedLawyer($meeting->participants) ?? $request->user());

        foreach ($decisions as $title) {
            Task::create([
                'assigned_to' => $owner->id,
                'title' => (string) $title,
                'ref' => $meeting->ref ?: 'M-'.$meeting->id,
                'due' => 'خلال أسبوع',
                'status' => 'مفتوحة',
                'tone' => 'b-amber',
            ]);
        }
        $meeting->update(['tasks_created' => true]);

        return back()->with('flash', 'تم تحويل '.count($decisions).' قرار إلى مهام لدى '.$owner->name);
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
        return Inertia::render('admin/meetlog', ['meetings' => $this->cards()]);
    }

    // تقارير الاجتماعات (يطابق meetReportsView)
    public function reports(Request $request): Response
    {
        return Inertia::render('admin/meetreports', ['meetings' => $this->cards()]);
    }

    private function cards()
    {
        return Meeting::latest('id')->get()->map(fn (Meeting $m) => $m->toFullCard());
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
