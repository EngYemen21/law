<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Meeting;
use App\Support\AppointmentBoard;
use App\Support\CalendarWindow;
use App\Support\ConsultBooking;
use App\Support\EventStatus;
use App\Support\MeetingTime;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تقويم الموظف — كل ارتباطات المكتب الزمنية (جلسات القضايا + الاجتماعات + الاستشارات).
 * نظير Lawyer\CalendarController بلا حصر بمحامٍ: الموظف ينسّق الجدولة للمكتب كلّه،
 * وكان يجدول المواعيد بلا أي نظرة على ما هو محجوز أصلاً.
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $canCourt = $isAdmin || $user->can(Permissions::COURT_PROCEEDINGS) || $user->can(Permissions::MANAGE_CASES_AND_FEES);
        $canMeetings = $isAdmin || $user->can(Permissions::MANAGE_MEETINGS) || $user->can(Permissions::SEND_MEETING_INVITES);
        $canBook = $isAdmin || $user->can(Permissions::SCHEDULE_APPOINTMENTS);
        $canManage = $isAdmin || $user->can(Permissions::MANAGE_BOOKINGS);
        $canVideo = $isAdmin || $user->can(Permissions::RUN_VIDEO_SESSIONS) || $user->can(Permissions::RECEIVE_CONSULTS);

        // نافذة زمنية: الغرض نظرة على ما هو محجوز قبل جدولة موعد، لا أرشيف المكتب كلّه.
        // الترتيب بالموعد لا بالمعرّف: مع latest('id') كان السقف يقتطع الأقدم إنشاءً — وهي
        // غالباً الأقرب انعقاداً — فتختفي من التقويم جلسات هذا الأسبوع لصالح ما حُجز للتوّ.
        // بلا حدّ كانت كل جلسة واجتماع واستشارة في تاريخ المكتب تُحمَّل في حمولة Inertia واحدة.
        $window = CalendarWindow::forOffice();

        $hearings = CaseHearing::with('legalCase')
            ->where($window)
            ->orderByRaw('starts_at is null')->orderBy('starts_at')->limit(CalendarWindow::LIMIT)->get()
            ->map(fn (CaseHearing $h) => [
                'kind' => 'جلسة',
                'kindKey' => 'hearing',
                'tone' => 'b-blue',
                'title' => $canCourt ? ($h->title.' — قضية '.($h->legalCase?->number ?? '')) : 'جلسة قضائية مجدولة لدى المستشار',
                'day' => $h->day,
                'time' => $h->time,
                'where' => $canCourt ? $h->court : 'المحكمة',
                'status' => EventStatus::forHearing($h),
                'statusTone' => EventStatus::toneForHearing($h),
                'startsAt' => $h->startMoment()?->toIso8601String(),
                // المدّة المتوقّعة إن أُدخلت — وإلا لا مدّة تُعرض (لا نهاية مختلَقة للجلسة)
                'durationMin' => $h->duration_min,
            ]);

        $meetings = Meeting::where($window)
            ->orderByRaw('starts_at is null')->orderBy('starts_at')->limit(CalendarWindow::LIMIT)->get()
            ->map(fn (Meeting $m) => [
                'kind' => 'اجتماع',
                'kindKey' => 'meeting',
                'tone' => 'b-cyan',
                'title' => $canMeetings ? $m->title : 'اجتماع عمل مجدول',
                'day' => $m->when_label,
                'time' => null,
                'where' => $canMeetings ? ($m->client_name ?: 'داخلي') : 'مكتب العمل',
                'status' => EventStatus::forMeeting($m),
                'statusTone' => EventStatus::toneForMeeting($m),
                // الخام لا المُبدَّل: `?: now()` كان يرفع اجتماعاً بلا موعد إلى وسط القائمة بدل الذيل.
                'startsAt' => $m->starts_at?->toIso8601String(),
            ]);

        $consults = Consult::with(['appointment', 'user'])->whereNotIn('status', ['ملغاة'])
            ->where($window)
            ->orderByRaw('starts_at is null')->orderBy('starts_at')->limit(CalendarWindow::LIMIT)->get()
            ->map(fn (Consult $c) => [
                'kind' => 'استشارة',
                'kindKey' => 'consult',
                'tone' => 'b-green',
                'title' => 'استشارة: '.$c->subject,
                'day' => $c->when_label ?: $c->day,
                'time' => $c->time,
                'where' => $c->channel === 'حضورية' ? $c->placeLabel() : 'جلسة مرئية بالمنصة',
                'status' => EventStatus::forConsult($c),
                'statusTone' => EventStatus::toneForConsult($c),
                'startsAt' => ($c->starts_at ?: MeetingTime::parse($c->day ?? '', $c->time ?? ''))?->toIso8601String(),
            ]);

        // التبويب الزمني موحّد: منظر «الأحداث» (أعلاه) ومنظر «المواعيد» (اللوحة نفسها
        // التي كانت شاشة «جدولة المواعيد» المستقلّة) في صفحة واحدة.
        // والإدارة تفتح نفس المتحكّم بصفحة باسمها (نطاق المكتب نفسه).
        return Inertia::render($user->isAdmin() ? 'admin/calendar' : 'employee/calendar', array_merge([
            // فرز زمني **بعد** الدمج: كل نوع مرتّب داخلياً، لكن concat وحده يعطي ثلاث
            // قوائم مكدّسة (كل الجلسات ثم كل الاجتماعات ثم كل الاستشارات) — فتظهر
            // استشارة هذا الأسبوع بعد اجتماع الشهر القادم. و«بلا موعد» في الذيل.
            'events' => $hearings->concat($meetings)->concat($consults)
                ->sortBy(fn (array $e) => [$e['startsAt'] === null, $e['startsAt']])
                ->values(),
            'feedUrl' => $user->calendarFeedUrl(),
            // «طلب استشارة نيابةً عن العميل» بجانب «حجز موعد جديد» — يُحمَّل عند فتح النموذج وحده
            'consultRequestForm' => ConsultBooking::onBehalfForm(),
            'webcalUrl' => $user->calendarWebcalUrl(),
            'can' => [
                'book' => $canBook,
                'manage' => $canManage,
                'enterRoom' => $canVideo,
                'court' => $canCourt,
                'meetings' => $canMeetings,
                'approve' => $isAdmin,
            ],
        ], AppointmentBoard::data($user)));
    }
}
