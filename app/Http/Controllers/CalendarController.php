<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CaseHearing;
use App\Models\Meeting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تقويم العميل — أحداثه الحقيقية (مواعيد الاستشارات + جلسات القضايا + الاجتماعات) مع رابط
 * «أضف إلى تقويم جوجل» لكل حدث (بلا مفاتيح/OAuth — يفتح جوجل بالبيانات جاهزة).
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $uid = $request->user()->id;

        $appts = Appointment::where('user_id', $uid)->latest('id')->get()
            ->map(fn (Appointment $a) => [
                'kind' => 'موعد',
                'tone' => 'b-cyan',
                'title' => $a->type,
                'day' => $a->day,
                'time' => $a->time,
                'where' => $a->branch,
                'status' => $a->status,
                'gcal' => self::gcalUrl($a->type, $a->day, $a->time, $a->branch),
            ]);

        $hearings = CaseHearing::whereHas('legalCase', fn ($q) => $q->where('user_id', $uid))
            ->latest('id')->get()
            ->map(fn (CaseHearing $h) => [
                'kind' => 'جلسة قضية',
                'tone' => 'b-amber',
                'title' => $h->title.' (قضية '.$h->legalCase?->number.')',
                'day' => $h->day,
                'time' => $h->time,
                'where' => $h->court ?: 'المحكمة',
                'status' => $h->status,
                'gcal' => self::gcalUrl($h->title, $h->day, $h->time, $h->court),
            ]);

        $meetings = Meeting::where('user_id', $uid)->latest('id')->get()
            ->map(fn (Meeting $m) => [
                'kind' => 'اجتماع',
                'tone' => 'b-green',
                'title' => $m->title,
                'day' => $m->when_label,
                'time' => null,
                'where' => $m->joinLink(),
                'status' => $m->status,
                'gcal' => self::gcalUrl($m->title, $m->when_label, null, $m->joinLink()),
            ]);

        return Inertia::render('calendar', [
            'events' => $appts->concat($hearings)->concat($meetings)->values(),
        ]);
    }

    /**
     * رابط «أضف إلى تقويم جوجل» — يفتح نموذج إنشاء حدث بالعنوان والتفاصيل جاهزة.
     * (التواريخ نصوص عربية حرة حالياً، فتوضع في التفاصيل ويحدّد المستخدم الوقت في جوجل —
     * المزامنة الدقيقة بالوقت تنتظر تحويل التواريخ لأعمدة datetime.)
     */
    private static function gcalUrl(string $title, ?string $day, ?string $time, ?string $where): string
    {
        $when = trim(($day ?? '').($time ? ' · '.$time : ''));
        $details = 'موعد لدى مكتب سلاسل بابل للمحاماة'.($when !== '' ? "\nالتوقيت: {$when}" : '');

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $title,
            'details' => $details,
            'location' => $where ?: '',
        ]);
    }
}
