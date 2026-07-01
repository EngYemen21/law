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
}
