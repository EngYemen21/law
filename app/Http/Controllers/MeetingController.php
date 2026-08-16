<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MeetingController extends Controller
{
    // قائمة اجتماعات العميل الحالي (تقسّمها الواجهة إلى قادمة/سابقة)
    public function index(Request $request): Response
    {
        $meetings = Meeting::where('user_id', $request->user()->id)
            ->latest('id')->get()
            ->map(fn (Meeting $m) => $m->toCard());

        return Inertia::render('meetings', [
            'meetings' => $meetings,
        ]);
    }

    // غرفة الاجتماع المضمّنة للعميل — تضمين Zoom داخل المنصّة (?ref=M-…)
    public function room(Request $request): Response
    {
        $meeting = Meeting::where('ref', (string) $request->query('ref'))->firstOrFail();
        abort_unless($meeting->user_id === $request->user()->id, 403);
        abort_unless($meeting->canJoin(), 403, 'لا يمكن دخول الجلسة إلا قبل موعدها بـ 5 دقائق.');

        return Inertia::render('meetingroom', [
            'meeting' => $meeting->toCard(),
            'selfName' => $request->user()->name,
        ]);
    }
}
