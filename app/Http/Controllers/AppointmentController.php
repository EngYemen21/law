<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Support\AppointmentCardPdf;
use App\Support\Mask;
use App\Support\PdfRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Browsershot\Browsershot;

class AppointmentController extends Controller
{
    /**
     * تبويب «المواعيد» طُوي في التبويب الزمني الموحّد `/calendar`، وصفحته
     * (resources/js/pages/appointments.tsx) صارت منظراً داخله لا صفحة Inertia مستقلّة.
     *
     * المسار يبقى ويُحوِّل — لا يُحذف: بريد تأكيد الموعد وإشعارات سابقة تشير إلى
     * /appointments، وحذفه يعطي 404 لكل من يفتح رسالة قديمة.
     *
     * لاستعادة التبويب المستقلّ: أعِد الجسم المعلّق أدناه، وأعِد عنصر التنقّل في
     * resources/js/lib/data.ts (المدخلان المعلّقان appts في TITLES وVIEW_ROUTE).
     *
     * الجسم السابق (محفوظ عمداً):
     *   $appointments = Appointment::where('user_id', $request->user()->id)
     *       ->with(['user', 'consult'])
     *       ->latest('id')->get()
     *       ->map(fn (Appointment $a) => $a->toCard($request->user()));
     *
     *   return Inertia::render('appointments', ['appointments' => $appointments]);
     *
     * القائمة نفسها تُبنى الآن في CalendarController::index بنفس الاستعلام حرفياً.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('calendar');
    }

    /**
     * بطاقة الموعد PDF — بنفس تصميم .apptx المعروض في الواجهة، مُصيَّرة فعلياً عبر Browsershot
     * (كروم مخفي حقيقي) لا تحويل صورة/محاكاة. ببيانات حقيقية من سجلّ الموعد فقط.
     */
    public function card(Request $request, Appointment $appointment): \Symfony\Component\HttpFoundation\Response
    {
        $user = $request->user();
        abort_unless(
            $appointment->user_id === $user->id ||
            $appointment->lawyer_id === $user->id ||
            $user->isAdmin() ||
            $user->isEmployee() ||
            $user->isLawyer(),
            403
        );

        $remote = $appointment->type === 'استشارة مرئية'
            || str_contains((string) $appointment->place, 'إلكتروني')
            || str_contains((string) $appointment->place, 'هاتفية')
            || str_contains((string) $appointment->place, 'بُعد');
        $place = $remote ? 'عن بُعد' : (string) $appointment->place;
        $address = $remote ? 'جلسة عن بُعد — يُرسل الرابط قبل الموعد' : (string) $appointment->place;
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

        return PdfRenderer::render($html, $appointment->ext_id.'.pdf');
    }
}
