<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\User;
use App\Services\IcalendarService;
use App\Support\CalendarWindow;
use App\Support\TimelineCard;
use App\Support\TimelineQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    /** خيارات الترشيح المسموحة — تُعرض للواجهة ويُتحقّق منها خادمياً. */
    private const SORTS = ['asc', 'desc'];

    /**
     * التبويب الزمني الموحّد — ترشيح وبحث وتصفيح **خادمية**.
     *
     * كانت الصفحة تُحمّل الأنواع الأربعة كاملةً في حمولة واحدة ثم تدمجها الواجهة: لا بحث ولا
     * ترشيح، وحجم الحمولة ينمو مع عمر الحساب بلا سقف. الآن TimelineQuery يتّحد على مستوى
     * القاعدة ويُعيد صفحة واحدة، وTimelineCard يُحمّل نماذج تلك الصفحة وحدها.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'kind' => ['nullable', 'string', 'in:all,'.implode(',', TimelineQuery::KINDS)],
            'status' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', self::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per' => ['nullable', 'integer', 'in:12,24,48'],
        ]);

        $perPage = (int) ($filters['per'] ?? 12);
        $page = (int) ($filters['page'] ?? 1);

        $paginator = TimelineQuery::paginate($user->id, $filters, $perPage, $page);

        return Inertia::render('calendar', [
            'events' => TimelineCard::hydrate(collect($paginator->items()), $user),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $perPage,
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'counts' => TimelineQuery::countsByKind($user->id, $filters),
            'statuses' => TimelineQuery::statuses($user->id),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'kind' => $filters['kind'] ?? 'all',
                'status' => $filters['status'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'sort' => $filters['sort'] ?? 'asc',
                'per' => $perPage,
            ],
            // «مواعيدي»: نوع واحد ببطاقته الغنيّة (QR · PDF · حالة السداد) — يبقى كاملاً
            // ضمن نافذة العميل لأنه مصدر البطاقة لا قائمة تصفَّح.
            'appointments' => Appointment::where('user_id', $user->id)->with(['user', 'consult'])
                ->where(CalendarWindow::forClient())
                ->orderByRaw('starts_at IS NULL')->orderBy('starts_at')
                ->limit(CalendarWindow::LIMIT)->get()
                ->map(fn (Appointment $a) => $a->toCard($user))->values(),
            'feedUrl' => $user->calendarFeedUrl(),
            'webcalUrl' => $user->calendarWebcalUrl(),
        ]);
    }

    public function feed(User $user, string $token): HttpResponse
    {
        // التحقق من صحة رمز الأمان الثابت للمستخدم
        abort_unless(hash_equals($user->calendarToken(), $token), 403, 'رمز التغذية غير صالح.');

        $ics = IcalendarService::feedForUser($user);

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="legal-calendar-'.$user->id.'.ics"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
