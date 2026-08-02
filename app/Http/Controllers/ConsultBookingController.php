<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use App\Support\Specialties;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * حجز استشارة ذكي من العميل: اختيار التخصّص → محامون مرتّبون بأولوية الذكاء الاصطناعي
 * (بنفس التخصّص + سجلّ النجاح) → منتقي تاريخ وفترات متاحة (منع الحجز المزدوج) → حجز حقيقي.
 */
class ConsultBookingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('book', [
            'prices' => Setting::consultPrices(),
            'specialties' => Specialties::all(),
        ]);
    }

    /**
     * تفرّغ المحامين المتخصّصين ليوم مُعطى (JSON) — يغذّي القائمة المنسدلة وشبكة الفترات والبدائل.
     */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'specialty' => ['nullable', 'string', 'max:80'],
            'subject' => ['nullable', 'string', 'max:120'],
            'date' => ['nullable', 'date'],
        ]);

        $day = LawyerAvailability::resolveDate($data['date'] ?? null);
        $lawyers = LawyerAvailability::rankedSpecialists(
            $data['specialty'] ?? '',
            $data['subject'] ?? null,
            $day->toDateString(),
        );

        return response()->json([
            'date' => $day->toDateString(),
            'lawyers' => $lawyers,
        ]);
    }

    // الخطوة 1: طلب استشارة (النوع/التخصّص فقط) — يُرسل للتسعير، ثم تُكمل الرحلة في «استشاراتي»
    // (فاتورة الإدارة → دفع محاكى → اختيار الموعد). مطابق لتصميم رحلة الحجز.
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:office,video,phone'],
            'subject' => ['nullable', 'string', 'max:120'],
            'specialty' => ['nullable', 'string', 'max:80'],
        ]);

        $consult = ConsultBooking::request($request->user(), [
            'type' => $data['type'],
            'subject' => $data['subject'] ?? null,
            'specialty' => $data['specialty'] ?? null,
        ]);

        return redirect()->route('myconsults')
            ->with('flash', "تم إرسال طلب استشارتك ({$consult->ref}) — بانتظار تسعير المكتب لسداد الفاتورة ثم اختيار الموعد.");
    }
}
