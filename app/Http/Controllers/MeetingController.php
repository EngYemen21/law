<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Support\Notify;
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

        // الاستشارات المرئية للعميل لإعطاء رؤية 360 متكاملة لكافة الجلسات
        $videoConsults = Consult::where('user_id', $userId)
            // مرئية فعلاً: التبويب اسمه «الاستشارات المرئية» وكان يعرض الهاتفية والحضورية أيضاً
            ->where('channel', 'مرئية')
            ->whereIn('session', ['بانتظار الجلسة', 'جلسة جارية'])
            ->latest('id')->take(4)->get()
            ->map(fn (Consult $c) => [
                'id' => $c->id,
                'ref' => $c->ref,
                'subject' => $c->subject ?: 'استشارة قانونية',
                'channel' => $c->channel,
                'when' => $c->when_label ?: 'بانتظار تحديد الموعد',
                'canJoin' => $c->canJoin(),
                'joinLink' => $c->joinLink($request->user()),
                'status' => $c->status,
                'session' => $c->session,
                // البديل يصف الغياب: «مستشار معتمد» كانت تُستعمل مكان **لا محامي
                // مُسنَد**، فتقرأ اعتماداً حيث لا إسناد أصلاً.
                'lawyer' => $c->lawyer ?: 'لم يُسنَد بعد',
            ]);

        $stats = [
            'total' => $meetings->count(),
            'upcoming' => $upcoming->count(),
            'past' => $past->count(),
            'approvedMinutes' => $meetings->filter(fn ($m) => ! empty($m['minutes']) || ! empty($m['summary']))->count(),
            'videoConsultsCount' => $videoConsults->count(),
        ];

        return Inertia::render('meetings', [
            'meetings' => $meetings,
            'stats' => $stats,
            'nextMeeting' => $nextMeeting,
            'videoConsults' => $videoConsults,
        ]);
    }

    // غرفة الاجتماع المضمّنة للعميل — تضمين Zoom داخل المنصّة (?ref=M-…)
    public function room(Request $request): Response
    {
        $meeting = Meeting::where('ref', (string) $request->query('ref'))->firstOrFail();
        abort_unless($meeting->user_id === $request->user()->id, 403);
        // رسالة مميّزة لكل حالة — «انتهت» توحي بمراجعة الملخص، و«لم يحن» تدعو للانتظار
        abort_unless($meeting->canJoin(), 403, $meeting->liveState()[0] === 'past'
            ? 'انتهت جلسة هذا الاجتماع — لم يعد الدخول متاحاً.'
            : 'لم يحن موعد الجلسة بعد — يُفعَّل الدخول قبل الموعد بـ5 دقائق.');

        return Inertia::render('meetingroom', [
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
        abort_unless(in_array($meeting->status, ['قادم', 'مؤجل'], true), 422, 'طلب تغيير الموعد متاح للاجتماعات القادمة فقط.');

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
