<?php

namespace App\Http\Controllers\Lawyer;

use App\Http\Controllers\Controller;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\Meeting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تقويم المحامي — أحداث حقيقية: جلسات قضاياه + اجتماعاته، بدل تقويم العميل الساكن.
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $lawyerId = $request->user()->id;

        // جلسات قضايا هذا المحامي
        $caseIds = LegalCase::where('assigned_lawyer_id', $lawyerId)->pluck('id');
        $hearings = CaseHearing::whereIn('case_id', $caseIds)->with('legalCase')->latest('id')->get()
            ->map(fn (CaseHearing $h) => [
                'kind' => 'جلسة',
                // النوع يحدّد مفردة الحالة، فتختار الواجهة خريطة النغمة الصحيحة لها
                'kindKey' => 'hearing',
                'tone' => 'b-blue',
                'title' => $h->title.' — قضية '.($h->legalCase?->number ?? ''),
                'day' => $h->day,
                'time' => $h->time,
                'where' => $h->court,
                'status' => $h->status,
            ]);

        // اجتماعات أنشأها هذا المحامي
        $meetings = Meeting::where('created_by', $lawyerId)->latest('id')->get()
            ->map(fn (Meeting $m) => [
                'kind' => 'اجتماع',
                'kindKey' => 'meeting',
                'tone' => 'b-cyan',
                'title' => $m->title,
                'day' => $m->when_label,
                'time' => null,
                'where' => $m->client_name ?: 'داخلي',
                'status' => $m->status,
            ]);

        return Inertia::render('lawyer/calendar', [
            'events' => $hearings->concat($meetings)->values(),
        ]);
    }
}
