<?php

namespace App\Http\Controllers;

use App\Models\Consult;
use App\Support\ConsultBooking;
use App\Support\Specialties;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «حجز استشارة» للعميل: القناة والمجال والموضوع والوقائع → طلبٌ بانتظار تسعير المكتب →
 * سداد الفاتورة عبر ميسّر → **المكتب يحدّد الموعد** ويُبلغ العميل (قرار المالك 2026-09-14:
 * العميل لا يختار موعده ولا محاميه).
 */
class ConsultBookingController extends Controller
{
    public function index(Request $request): Response
    {
        // طلبات العميل في دورة الحجز (بانتظار التسعير/السداد/تحديد الموعد) — تُعرض فوق النموذج
        $pending = Consult::where('user_id', $request->user()->id)
            ->whereIn('status', Consult::PRE_SESSION_STATUSES)
            ->latest('id')->get()
            ->map(fn (Consult $c) => $c->toClientCard());

        return Inertia::render('book', [
            'specialties' => Specialties::all(),
            'pending' => $pending,
        ]);
    }

    // طلب استشارة يُرسل للتسعير، ثم تُكمل الرحلة في «استشاراتي» (فاتورة → سداد → موعدٌ يحدّده المكتب)
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:office,video,phone'],
            'subject' => ['required', 'string', 'max:120'],
            // الوقائع حقلٌ مستقلّ — كانت تُدمج في الموضوع وتُقصّ عند 120 حرفاً
            'details' => ['nullable', 'string', 'max:2000'],
            'specialty' => ['nullable', 'string', 'max:80'],
        ]);

        $consult = ConsultBooking::request($request->user(), [
            'type' => $data['type'],
            'subject' => $data['subject'],
            'details' => $data['details'] ?? null,
            'specialty' => $data['specialty'] ?? null,
        ]);

        return redirect()->route('myconsults')
            ->with('flash', "تم إرسال طلب استشارتك ({$consult->ref}) — بانتظار تسعير المكتب، وبعد السداد يحدّد المكتب الموعد ويُبلغك به.");
    }
}
