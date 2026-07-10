<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\ConsultBooking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * حجز استشارة مباشر من العميل (بلا تذكرة) — يحفظ Consult+Appointment حقيقيين.
 */
class ConsultBookingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('book', ['prices' => Setting::consultPrices()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:office,video,phone'],
            'day' => ['required', 'string', 'max:60'],
            'time' => ['required', 'string', 'max:32'],
            'subject' => ['nullable', 'string', 'max:120'],
            'branch' => ['nullable', 'string', 'max:80'],
        ]);

        $consult = ConsultBooking::create($request->user(), $data);

        return redirect()->route('myconsults')
            ->with('flash', "تم تأكيد حجز استشارتك برقم {$consult->ref}.");
    }
}
