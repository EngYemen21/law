<?php

namespace App\Http\Controllers\Lawyer;

use App\Http\Controllers\Controller;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Support\CalendarWindow;
use App\Support\EventStatus;
use App\Support\MeetingTime;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تقويم المحامي — أحداث حقيقية ومحدثة: جلسات قضاياه + اجتماعاته + استشاراته المسندة إليه
 * مع تغذية المزامنة الحية (ICS) للاشتراك في أي برنامج تقويم.
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $lawyer = $request->user();
        $lawyerId = $lawyer->id;

        // 1. جلسات قضايا هذا المحامي
        $caseIds = LegalCase::where('assigned_lawyer_id', $lawyerId)->pluck('id');
        $window = CalendarWindow::forLawyer();

        $hearings = CaseHearing::whereIn('case_id', $caseIds)->with('legalCase')->where($window)->orderByRaw('starts_at is null')->orderBy('starts_at')->limit(CalendarWindow::LIMIT)->get()
            ->map(fn (CaseHearing $h) => [
                'kind' => 'جلسة',
                'kindKey' => 'hearing',
                'tone' => 'b-blue',
                'title' => $h->title.' — قضية '.($h->legalCase?->number ?? ''),
                'day' => $h->day,
                'time' => $h->time,
                'where' => $h->court,
                'status' => EventStatus::forHearing($h),
                'statusTone' => EventStatus::toneForHearing($h),
                'startsAt' => $h->startMoment()?->toIso8601String(),
                // المدّة المتوقّعة إن أُدخلت — وإلا لا مدّة تُعرض (لا نهاية مختلَقة للجلسة)
                'durationMin' => $h->duration_min,
            ]);

        // 2. اجتماعات المحامي
        // بالإسناد وحده: `created_by` نصُّ اسمٍ يشاركه الزملاء فيُدخل اجتماعات غيره
        $meetings = Meeting::where('assigned_lawyer_id', $lawyerId)->where($window)->orderByRaw('starts_at is null')->orderBy('starts_at')->limit(CalendarWindow::LIMIT)->get()
            ->map(function (Meeting $m) {
                return [
                    'kind' => 'اجتماع',
                    'kindKey' => 'meeting',
                    'tone' => 'b-cyan',
                    'title' => $m->title,
                    'day' => $m->when_label,
                    'time' => null,
                    'where' => $m->client_name ?: 'داخلي',
                    'status' => EventStatus::forMeeting($m),
                    'statusTone' => EventStatus::toneForMeeting($m),
                    'startsAt' => $m->starts_at?->toIso8601String(),
                ];
            });

        // 3. استشارات مسندة للمحامي
        $consults = Consult::with(['appointment', 'user'])->where('assigned_lawyer_id', $lawyerId)->whereNotIn('status', ['ملغاة'])->where($window)->orderByRaw('starts_at is null')->orderBy('starts_at')->limit(CalendarWindow::LIMIT)->get()
            ->map(function (Consult $c) {
                $start = $c->starts_at ?: MeetingTime::parse($c->day ?? '', $c->time ?? '');

                return [
                    'kind' => 'استشارة',
                    'kindKey' => 'consult',
                    'tone' => 'b-green',
                    'title' => 'استشارة: '.$c->subject,
                    'day' => $c->when_label ?: $c->day,
                    'time' => $c->time,
                    'where' => $c->channel === 'حضورية' ? $c->placeLabel() : 'جلسة مرئية بالمنصة',
                    'status' => EventStatus::forConsult($c),
                    'statusTone' => EventStatus::toneForConsult($c),
                    'startsAt' => $start?->toIso8601String(),
                ];
            });

        return Inertia::render('lawyer/calendar', [
            // فرز زمني **بعد** الدمج: concat وحده يعطي ثلاث قوائم مكدّسة حسب النوع
            // لا تقويماً. و«بلا موعد» في الذيل — نفس دلالة بقيّة اللوحات.
            'events' => $hearings->concat($meetings)->concat($consults)
                ->sortBy(fn (array $e) => [$e['startsAt'] === null, $e['startsAt']])
                ->values(),
            'feedUrl' => $lawyer->calendarFeedUrl(),
            'webcalUrl' => $lawyer->calendarWebcalUrl(),
        ]);
    }
}
