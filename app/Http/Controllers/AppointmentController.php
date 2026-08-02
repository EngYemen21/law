<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    // قائمة مواعيد العميل الحالي (تقسّمها الواجهة إلى قادمة/سابقة)
    public function index(Request $request): Response
    {
        $appointments = Appointment::where('user_id', $request->user()->id)
            ->with(['user', 'consult'])
            ->latest('id')->get()
            ->map(fn (Appointment $a) => $a->toCard());

        return Inertia::render('appointments', [
            'appointments' => $appointments,
        ]);
    }
}
