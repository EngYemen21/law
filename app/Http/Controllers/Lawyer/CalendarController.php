<?php

namespace App\Http\Controllers\Lawyer;

use App\Http\Controllers\Controller;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Services\IcalendarService;
use App\Support\MeetingTime;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تقويم المحامي — أحداث حقيقية ومحدثة: جلسات قضاياه + اجتماعاته + استشاراته المسندة إليه
 * مع روابط تقويم جوجل الدقيقة وتغذية المزامنة الحية.
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $lawyer = $request->user();
        $lawyerId = $lawyer->id;

        // 1. جلسات قضايا هذا المحامي
        $caseIds = LegalCase::where('assigned_lawyer_id', $lawyerId)->pluck('id');
        $hearings = CaseHearing::whereIn('case_id', $caseIds)->with('legalCase')->latest('id')->get()
            ->map(function (CaseHearing $h) {
                $start = MeetingTime::parse($h->day, $h->time);
                $gcal = IcalendarService::googleUrl(
                    title: $h->title.' — قضية '.($h->legalCase?->number ?? ''),
                    details: 'جلسة محكمة: '.($h->court ?: 'المحكمة المختصة'),
                    startsAt: $start,
                    durationMinutes: 60,
                    locationUrl: $h->court ?: 'المحكمة'
                );

                return [
                    'kind' => 'جلسة',
                    'kindKey' => 'hearing',
                    'tone' => 'b-blue',
                    'title' => $h->title.' — قضية '.($h->legalCase?->number ?? ''),
                    'day' => $h->day,
                    'time' => $h->time,
                    'where' => $h->court,
                    'status' => $h->status,
                    'gcal' => $gcal,
                ];
            });

        // 2. اجتماعات المحامي
        $meetings = Meeting::where(fn ($q) => $q->where('assigned_lawyer_id', $lawyerId)->orWhere('created_by', $lawyer->name))->latest('id')->get()
            ->map(function (Meeting $m) use ($lawyer) {
                $start = $m->starts_at ?: now();
                $link = $m->joinLink($lawyer);
                $gcal = IcalendarService::googleUrl(
                    title: 'اجتماع: '.$m->title,
                    details: 'اجتماع عمل بالمنصة — '.$m->when_label,
                    startsAt: $start,
                    durationMinutes: 60,
                    locationUrl: $link
                );

                return [
                    'kind' => 'اجتماع',
                    'kindKey' => 'meeting',
                    'tone' => 'b-cyan',
                    'title' => $m->title,
                    'day' => $m->when_label,
                    'time' => null,
                    'where' => $m->client_name ?: 'داخلي',
                    'status' => $m->status,
                    'gcal' => $gcal,
                ];
            });

        // 3. استشارات مسندة للمحامي
        $consults = Consult::with('appointment')->where('assigned_lawyer_id', $lawyerId)->whereNotIn('status', ['ملغاة'])->latest('id')->get()
            ->map(function (Consult $c) use ($lawyer) {
                $start = $c->starts_at ?: MeetingTime::parse($c->day ?? '', $c->time ?? '');
                $link = $c->joinLink($lawyer);
                $gcal = IcalendarService::googleUrl(
                    title: 'استشارة: '.$c->subject.' ('.$c->ref.')',
                    details: 'استشارة قانونية ('.$c->channel.') — العميل: '.($c->user?->name ?? 'عميل المنصة'),
                    startsAt: $start,
                    durationMinutes: $c->duration_min ?: 45,
                    locationUrl: $link
                );

                return [
                    'kind' => 'استشارة',
                    'kindKey' => 'consult',
                    'tone' => 'b-green',
                    'title' => 'استشارة: '.$c->subject,
                    'day' => $c->when_label ?: $c->day,
                    'time' => $c->time,
                    'where' => $c->channel === 'حضورية' ? $c->placeLabel() : 'جلسة مرئية بالمنصة',
                    'status' => $c->status,
                    'gcal' => $gcal,
                ];
            });

        return Inertia::render('lawyer/calendar', [
            'events' => $hearings->concat($meetings)->concat($consults)->values(),
            'feedUrl' => $lawyer->calendarFeedUrl(),
            'webcalUrl' => $lawyer->calendarWebcalUrl(),
        ]);
    }
}
