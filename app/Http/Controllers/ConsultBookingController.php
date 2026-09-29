<?php

namespace App\Http\Controllers;

use App\Models\Consult;
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
    public function index(Request $request): Response
    {
        $user = $request->user();

        // طلبات استشارة العميل الحالية بدورة الحجز (بانتظار التسعير/السداد/تحديد الموعد)
        // تُعرض في نفس صفحة الحجز (مطابقة لدمج القائمة+النموذج بالتصميم المرجعي)
        $pending = Consult::where('user_id', $user->id)
            ->whereIn('status', Consult::PRE_SESSION_STATUSES)
            ->latest('id')->get()
            ->map(fn (Consult $c) => $c->toClientCard());

        // stats عُلّقت (2026-08-25): الواجهة المعاد تصميمها لا تعرضها — ثلاث استعلامات
        // كانت تُنفَّذ وتُرسَل بلا مستهلك. تُعاد بإزالة التعليق إن عادت للواجهة.
        // $activeConsultsCount = Consult::where('user_id', $user->id)
        //     ->whereIn('session', ['بانتظار الجلسة', 'جلسة جارية'])
        //     ->count();
        // $completedConsultsCount = Consult::where('user_id', $user->id)
        //     ->where('session', 'منتهية')
        //     ->count();
        // $stats = [
        //     'pendingCount' => $pending->count(),
        //     'activeCount' => $activeConsultsCount,
        //     'completedCount' => $completedConsultsCount,
        //     'totalRequested' => Consult::where('user_id', $user->id)->count(),
        // ];

        return Inertia::render('book', [
            'specialties' => Specialties::all(),
            'pending' => $pending,
            // 'stats' => $stats,
        ]);
    }

    /**
     * أوقات التفرّغ ليوم مُعطى (JSON) للعميل — شبكة الفترات وحدها؛ والمحامي يُسنَد بعد التأكيد.
     */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'specialty' => ['nullable', 'string', 'max:80'],
            'subject' => ['nullable', 'string', 'max:120'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $day = LawyerAvailability::resolveDate($data['date'] ?? null);

        // الأوقات وحدها — لا أسماء ولا أقسام ولا أحمال ولا نسب إنجاز (انظر `clientSlots`)
        return response()->json(array_merge(
            ['date' => $day->toDateString()],
            LawyerAvailability::clientSlots($data['specialty'] ?? '', $data['subject'] ?? null, $day->toDateString()),
        ));
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
