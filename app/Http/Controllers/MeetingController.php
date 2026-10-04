<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Meeting;
use App\Models\User;
use App\Support\Notify;
use App\Support\RoomDetails;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MeetingController extends Controller
{
    // قائمة اجتماعات العميل الحالي مع منظور 360 درجة للجلسات
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $meetings = Meeting::where('user_id', $userId)
            ->with('assignedLawyer')
            ->latest('id')->get()
            ->map(fn (Meeting $m) => $m->toCard());

        $upcoming = $meetings->filter(fn ($m) => $m['up'])->values();
        $past = $meetings->filter(fn ($m) => ! $m['up'])->values();

        // الاجتماع الأقرب القادم
        $nextMeeting = $upcoming->first();

        // **الاجتماعات وحدها** (جرد تبويبات العميل 2026-10-04، قرار المالك): كان هنا تبويب «الاستشارات المرئية»
        // يكرّر ما في «استشاراتي» — وبموعدٍ من النصّ المخزَّن `when_label` لا من `starts_at`، فعرض لـCN-2026-906
        // «29-09 · 10:00» و«استشاراتي» «03-10 · 03:17». الاستشارة وأزرارها (الدخول والتفاصيل) في «استشاراتي» والتقويم.

        $stats = [
            'total' => $meetings->count(),
            'upcoming' => $upcoming->count(),
            'past' => $past->count(),
            'approvedMinutes' => $meetings->filter(fn ($m) => ! empty($m['minutes']) || ! empty($m['summary']))->count(),
        ];

        return Inertia::render('meetings', [
            'meetings' => $meetings,
            'stats' => $stats,
            'nextMeeting' => $nextMeeting,
        ]);
    }

    // غرفة الاجتماع المضمّنة للعميل — تضمين Zoom داخل المنصّة (?ref=M-…)
    public function room(Request $request): Response|RedirectResponse
    {
        $meeting = Meeting::with('assignedLawyer')->where('ref', (string) $request->query('ref'))->firstOrFail();
        abort_unless($meeting->user_id === $request->user()->id, 403);
        // السبب الحقيقيّ من المصدر الواحد (`joinBlocker` عبر `RoomDetails::entryBlocker`) — كان
        // «انتهت» يُقال لاجتماعٍ ملغى أو فائت، والنصّ غير نصّ نقطة التوقيع
        if (($why = RoomDetails::entryBlocker($meeting, $request->user())) !== null) {
            return RoomDetails::refuse($request, $meeting, $why, 403);
        }

        return Inertia::render('meetingroom', [
            // عقد الغرفة — والخاصيّتان القديمتان باقيتان حتى تنتقل الواجهة إليه
            'room' => RoomDetails::for($meeting, $request->user()),
            'meeting' => $meeting->toCard(),
            'selfName' => $request->user()->name,
        ]);
    }

    /**
     * طلب العميل تغيير موعد اجتماع قادم — لم تكن له أي قناة بشأن الموعد.
     * إشعار للمحامي المسند والإدارة؛ إعادة الجدولة الفعلية قرار المكتب (meetings.reschedule).
     */
    public function changeRequest(Request $request, Meeting $meeting): RedirectResponse
    {
        abort_unless($meeting->user_id === $request->user()->id, 403);
        // لا يتكرّر وهو قيد المعالجة، ولا بعد سقف إعادة الجدولة (قرار المالك 2026-09-29) — `Meeting::changeRequestBlocker`
        if (($blocker = $meeting->changeRequestBlocker()) !== null) {
            abort(422, $blocker);
        }
        $meeting->forceFill(['reschedule_requested_at' => now()])->save();

        $message = "طلب العميل تغيير موعد الاجتماع «{$meeting->title}» ({$meeting->ref}) — {$meeting->when_label}. أعد جدولته من صفحة الاجتماع.";
        if ($meeting->assigned_lawyer_id) {
            Notify::send($meeting->assigned_lawyer_id, 'cal', 't-amber', $message);
        }
        foreach (User::where('role', Role::Admin)->get() as $admin) {
            Notify::send($admin->id, 'cal', 't-amber', $message);
        }

        return back()->with('flash', 'أُرسل طلبك للمكتب — سيتواصل معك فريقنا بشأن الموعد الجديد.');
    }
}
