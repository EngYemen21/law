<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Services\IcalendarService;
use App\Support\MeetingTime;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تقويم العميل والمكتب — عرض الأحداث المجمّعة وتوليد روابط تقويم جوجل والاشتراك الحي (Live iCal Feed).
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $uid = $user->id;

        // 1. المواعيد الحضورية والمكتبية
        $appts = Appointment::where('user_id', $uid)->latest('id')->get()
            ->map(function (Appointment $a) {
                $start = MeetingTime::parse($a->day, $a->time);
                $gcal = IcalendarService::googleUrl(
                    title: 'موعد: '.$a->type,
                    details: 'موعد لدى النظام الإداري لمكاتب المحاماة (المحامي: '.$a->lawyer.')',
                    startsAt: $start,
                    durationMinutes: 30,
                    locationUrl: $a->branch ?: 'مكتب المحاماة'
                );

                return [
                    'kind' => 'موعد',
                    'tone' => 'b-cyan',
                    'title' => $a->type,
                    'day' => $a->day,
                    'time' => $a->time,
                    'where' => $a->branch,
                    'status' => $a->status,
                    'gcal' => $gcal,
                ];
            });

        // 2. الاستشارات القانونية
        $consults = Consult::where('user_id', $uid)->whereNotIn('status', ['ملغاة'])->latest('id')->get()
            ->map(function (Consult $c) use ($user) {
                $start = $c->starts_at ?: MeetingTime::parse($c->day ?? '', $c->time ?? '');
                $link = $c->joinLink($user);
                $gcal = IcalendarService::googleUrl(
                    title: 'استشارة: '.$c->subject.' ('.$c->ref.')',
                    details: 'استشارة قانونية ('.$c->channel.') — المستشار: '.$c->lawyer,
                    startsAt: $start,
                    durationMinutes: $c->duration_minutes ?: 45,
                    locationUrl: $link
                );

                return [
                    'kind' => 'استشارة',
                    'tone' => 'b-blue',
                    'title' => $c->subject.' ('.$c->ref.')',
                    'day' => $c->when_label ?: $c->day,
                    'time' => $c->time,
                    'where' => $c->channel === 'حضورية' ? $c->branch : 'جلسة مرئية بالمنصة',
                    'status' => $c->status,
                    'gcal' => $gcal,
                ];
            });

        // 3. جلسات القضايا
        $hearings = CaseHearing::whereHas('legalCase', fn ($q) => $q->where('user_id', $uid))
            ->with('legalCase')->latest('id')->get()
            ->map(function (CaseHearing $h) {
                $start = MeetingTime::parse($h->day, $h->time);
                $gcal = IcalendarService::googleUrl(
                    title: $h->title.' (قضية '.($h->legalCase?->number ?: '—').')',
                    details: 'جلسة قضائية بمحكمة: '.($h->court ?: 'المحكمة المختصة'),
                    startsAt: $start,
                    durationMinutes: 60,
                    locationUrl: $h->court ?: 'المحكمة المختصة'
                );

                return [
                    'kind' => 'جلسة قضية',
                    'tone' => 'b-amber',
                    'title' => $h->title.' (قضية '.($h->legalCase?->number ?: '—').')',
                    'day' => $h->day,
                    'time' => $h->time,
                    'where' => $h->court ?: 'المحكمة',
                    'status' => $h->status,
                    'gcal' => $gcal,
                ];
            });

        // 4. الاجتماعات الرسمية
        $meetings = Meeting::where('user_id', $uid)->whereNotIn('status', ['ملغى'])->latest('id')->get()
            ->map(function (Meeting $m) use ($user) {
                $start = $m->starts_at ?: now();
                $link = $m->joinLink($user);
                $gcal = IcalendarService::googleUrl(
                    title: 'اجتماع: '.$m->title.' ('.$m->ref.')',
                    details: 'اجتماع رسمي بالمنصة — '.$m->when_label,
                    startsAt: $start,
                    durationMinutes: 60,
                    locationUrl: $link
                );

                return [
                    'kind' => 'اجتماع',
                    'tone' => 'b-green',
                    'title' => $m->title,
                    'day' => $m->when_label,
                    'time' => null,
                    'where' => 'غرفة المنصة',
                    'status' => $m->status,
                    'gcal' => $gcal,
                ];
            });

        return Inertia::render('calendar', [
            'events' => $appts->concat($consults)->concat($hearings)->concat($meetings)->values(),
            'feedUrl' => $user->calendarFeedUrl(),
            'webcalUrl' => $user->calendarWebcalUrl(),
        ]);
    }

    /**
     * موجز التغذية الحية لتقويم جوجل (RFC 5545 Live iCal Subscription Feed).
     */
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
