<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Rules\LawyerInBranch;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use App\Support\Specialties;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:office,video,phone'],
            'subject' => ['nullable', 'string', 'max:120'],
            'branch' => ['nullable', 'string', 'max:80'],
            'specialty' => ['nullable', 'string', 'max:80'],
            // العميل يختار أي محامٍ نشط (بلا تقييد بفرع) — Rule موحَّد يرفض غير المحامين والموقوفين.
            'lawyer_id' => ['required', 'integer', new LawyerInBranch],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        $startsAt = Carbon::parse($data['date'].' '.$data['time']);

        $consult = ConsultBooking::create($request->user(), [
            'type' => $data['type'],
            'subject' => $data['subject'] ?? null,
            'branch' => $data['branch'] ?? null,
            'specialty' => $data['specialty'] ?? null,
            'lawyer_id' => (int) $data['lawyer_id'],
            'starts_at' => $startsAt->toDateTimeString(),
            'duration' => LawyerAvailability::slotMinutes(),
            'day' => $startsAt->format('Y-m-d'),
            'time' => $data['time'],
        ]);

        return redirect()->route('myconsults')
            ->with('flash', "تم تأكيد حجز استشارتك برقم {$consult->ref}.");
    }
}
