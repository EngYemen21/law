<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Support\AppointmentCardPdf;
use App\Support\Mask;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Browsershot\Browsershot;

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

    /**
     * بطاقة الموعد PDF — بنفس تصميم .apptx المعروض في الواجهة، مُصيَّرة فعلياً عبر Browsershot
     * (كروم مخفي حقيقي) لا تحويل صورة/محاكاة. ببيانات حقيقية من سجلّ الموعد فقط.
     */
    public function card(Request $request, Appointment $appointment): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($appointment->user_id === $request->user()->id, 403);

        $remote = $appointment->type === 'استشارة مرئية'
            || str_contains((string) $appointment->branch, 'إلكتروني')
            || str_contains((string) $appointment->branch, 'هاتفية')
            || str_contains((string) $appointment->branch, 'بُعد');
        $place = $remote ? 'عن بُعد' : (string) $appointment->branch;
        $address = $remote ? 'جلسة عن بُعد — يُرسل الرابط قبل الموعد' : (string) $appointment->branch;
        $consult = $appointment->consult;
        $paid = $consult?->paid_at !== null;

        $html = AppointmentCardPdf::html([
            'no' => $appointment->ext_id,
            'type' => $appointment->type,
            'day' => $appointment->dayLabel(),
            'time' => $appointment->timeLabel(),
            'place' => $place,
            'client' => $appointment->user?->name ?: '—',
            'lawyer' => Mask::lawyer($appointment->lawyer),
            'consultRef' => $consult?->ref ?: '—',
            'address' => $address,
            'paid' => $paid,
            'payLabel' => $paid ? 'مدفوع' : 'بانتظار السداد',
            'qrSeed' => $appointment->ext_id,
        ]);

        return \App\Support\PdfRenderer::render($html, $appointment->ext_id.'.pdf');
    }
}
