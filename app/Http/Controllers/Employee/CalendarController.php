<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Meeting;
use App\Services\IcalendarService;
use App\Support\MeetingTime;
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

        // نافذة زمنية: الغرض نظرة على ما هو محجوز قبل جدولة موعد، لا أرشيف المكتب كلّه.
        // الترتيب بالموعد لا بالمعرّف: مع latest('id') كان السقف يقتطع الأقدم إنشاءً — وهي
        // غالباً الأقرب انعقاداً — فتختفي من التقويم جلسات هذا الأسبوع لصالح ما حُجز للتوّ.
        // بلا حدّ كانت كل جلسة واجتماع واستشارة في تاريخ المكتب تُحمَّل في حمولة Inertia واحدة.
        $from = now()->subDays(7);
        $to = now()->addDays(90);

        $hearings = CaseHearing::with('legalCase')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhereBetween('starts_at', [$from, $to]))
            ->orderByRaw('starts_at is null')->orderBy('starts_at')->limit(300)->get()
            ->map(fn (CaseHearing $h) => [
                'kind' => 'جلسة',
                'kindKey' => 'hearing',
                'tone' => 'b-blue',
                'title' => $h->title.' — قضية '.($h->legalCase?->number ?? ''),
                'day' => $h->day,
                'time' => $h->time,
                'where' => $h->court,
                'status' => $h->status,
                'gcal' => IcalendarService::googleUrl(
                    title: $h->title.' — قضية '.($h->legalCase?->number ?? ''),
                    details: 'جلسة محكمة: '.($h->court ?: 'المحكمة المختصة'),
                    startsAt: MeetingTime::parse($h->day, $h->time),
                    durationMinutes: 60,
                    locationUrl: $h->court ?: 'المحكمة'
                ),
            ]);

        $meetings = Meeting::where(fn ($q) => $q->whereNull('starts_at')->orWhereBetween('starts_at', [$from, $to]))
            ->orderByRaw('starts_at is null')->orderBy('starts_at')->limit(300)->get()
            ->map(fn (Meeting $m) => [
                'kind' => 'اجتماع',
                'kindKey' => 'meeting',
                'tone' => 'b-cyan',
                'title' => $m->title,
                'day' => $m->when_label,
                'time' => null,
                'where' => $m->client_name ?: 'داخلي',
                'status' => $m->status,
                'gcal' => IcalendarService::googleUrl(
                    title: 'اجتماع: '.$m->title,
                    details: 'اجتماع عمل بالمنصة — '.$m->when_label,
                    startsAt: $m->starts_at ?: now(),
                    durationMinutes: 60,
                    locationUrl: $m->joinLink($user)
                ),
            ]);

        $consults = Consult::with(['appointment', 'user'])->whereNotIn('status', ['ملغاة'])
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhereBetween('starts_at', [$from, $to]))
            ->orderByRaw('starts_at is null')->orderBy('starts_at')->limit(300)->get()
            ->map(fn (Consult $c) => [
                'kind' => 'استشارة',
                'kindKey' => 'consult',
                'tone' => 'b-green',
                'title' => 'استشارة: '.$c->subject,
                'day' => $c->when_label ?: $c->day,
                'time' => $c->time,
                'where' => $c->channel === 'حضورية' ? $c->placeLabel() : 'جلسة مرئية بالمنصة',
                'status' => $c->status,
                'gcal' => IcalendarService::googleUrl(
                    title: 'استشارة: '.$c->subject.' ('.$c->ref.')',
                    details: 'استشارة قانونية ('.$c->channel.') — العميل: '.($c->user?->name ?? 'عميل المنصة'),
                    startsAt: $c->starts_at ?: MeetingTime::parse($c->day ?? '', $c->time ?? ''),
                    durationMinutes: $c->duration_min ?: 45,
                    locationUrl: $c->joinLink($user)
                ),
            ]);

        return Inertia::render('employee/calendar', [
            'events' => $hearings->concat($meetings)->concat($consults)->values(),
            'feedUrl' => $user->calendarFeedUrl(),
            'webcalUrl' => $user->calendarWebcalUrl(),
        ]);
    }
}
