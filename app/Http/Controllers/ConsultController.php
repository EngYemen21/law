<?php

namespace App\Http\Controllers;

use App\Models\Consult;
use App\Models\Invoice;
use App\Services\Payments\GatewayCallback;
use App\Services\Payments\PaymentGateways;
use App\Support\Booking\BookingStaff;
use App\Support\ConsultBooking;
use App\Support\ConsultReport;
use App\Support\Notify;
use App\Support\PaymentReconciler;
use App\Support\PdfRenderer;
use App\Support\Permissions;
use App\Support\ReportPrint;
use App\Support\RoomDetails;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Browsershot\Browsershot;

/**
 * استشارات العميل — «استشاراتي» (يطابق myConsultsView) وغرفة الجلسة المرئية،
 * ودورة الحجز المطابقة للتصميم: دفع الفاتورة عبر بوّابة الدفع ثم اختيار الموعد بعد السداد.
 */
class ConsultController extends Controller
{
    // قائمة استشارات العميل الحالي مع منظور 360 درجة للجلسات
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $consults = Consult::with(['user', 'appointment', 'assignedLawyer', 'invoice'])
            ->where('user_id', $userId)
            ->latest('id')->get()
            ->map(fn (Consult $c) => $c->toClientCard());

        $upcoming = $consults->filter(fn ($c) => in_array($c['session'], ['بانتظار الجلسة', 'جلسة جارية'])
            && ! ($c['missed'] ?? false)
            && ! in_array($c['status'], ['بانتظار التسعير', 'بانتظار السداد', 'بانتظار تحديد الموعد', 'بانتظار استكمال البيانات', 'ملغاة'])
        )->values();
        $completed = $consults->filter(fn ($c) => $c['session'] === 'منتهية')->values();
        $pendingBooking = $consults->filter(fn ($c) => in_array($c['status'], ['بانتظار التسعير', 'بانتظار السداد', 'بانتظار تحديد الموعد', 'بانتظار استكمال البيانات'])
            || ($c['missed'] ?? false)
            || ($c['session'] ?? '') === 'لم تُعقد'
        )->values();

        // أقرب استشارة قادمة
        $nextConsult = $upcoming->first();

        $stats = [
            'total' => $consults->count(),
            'upcoming' => $upcoming->count(),
            'completed' => $completed->count(),
            'pendingBooking' => $pendingBooking->count(),
            'reportsCount' => $consults->filter(fn ($c) => ! in_array($c['status'], ['بانتظار التسعير', 'بانتظار السداد', 'بانتظار تحديد الموعد', 'ملغاة']))->count(),
        ];

        return Inertia::render('myconsults', [
            'consults' => $consults,
            'stats' => $stats,
            'nextConsult' => $nextConsult,
        ]);
    }

    // دفع فاتورة الاستشارة عبر بوّابة الدفع → يعيد التوجيه لصفحة الدفع المستضافة.
    // التأكيد عبر webhook/callback (مصدر الحقيقة)؛ لا دفع بلا بوّابة مهيّأة.
    public function pay(Request $request, Consult $consult): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($consult->user_id === $request->user()->id, 403);
        abort_unless($consult->status === 'بانتظار السداد', 422, 'لا يوجد مبلغ مستحق للسداد على هذه الاستشارة.');
        abort_unless(app(PaymentGateways::class)->default()->isConfigured(), 503, 'بوّابة الدفع غير مهيّأة.');

        // رابط العودة من أصل الطلب نفسه (لا APP_URL) — فيعود المتصفّح لنفس النطاق وتبقى الجلسة صالحة
        $callback = $request->getSchemeAndHttpHost().route('consults.pay.callback', $consult, absolute: false);
        $url = ConsultBooking::initiatePayment($consult, $callback);
        if ($url === null) {
            return back()->with('error', 'تعذّر بدء الدفع حالياً، حاول بعد قليل.');
        }

        // يعيد Inertia توجيه المتصفّح لصفحة الدفع (X-Inertia-Location) — الاستشارة تبقى بانتظار السداد حتى التأكيد
        return Inertia::location($url);
    }

    // العودة من صفحة الدفع — تحقّق خادميّ صارم (لا يُوثَق بمعطيات الـURL): يُعاد جلب الدفعة والتحقّق منها.
    public function payCallback(Request $request, Consult $consult): RedirectResponse
    {
        abort_unless($consult->user_id === $request->user()->id, 403);

        // بعد الدفع يعود العميل لدردشة التذكرة (حيث لوحة اختيار الموعد)؛ وإن لم ترتبط بتذكرة فإلى «استشاراتي».
        $back = fn (): RedirectResponse => $consult->ticket_id
            ? redirect()->route('tickets.show', $consult->ticket)
            : redirect()->route('myconsults');

        // اربط الدفعة بفاتورة هذه الاستشارة تحديدًا (لا تسوية دفعة تخصّ فاتورة أخرى)
        if (GatewayCallback::confirm($request, Invoice::whereKey($consult->invoice?->id))) {
            return $back()->with('success', 'تم تأكيد الدفع — سوف يتم تحديد موعد جلستك مع المستشار المختص ويصلك إشعار به.');
        }

        // إن كانت الفاتورة عُلّمت كمدفوعة أصلًا (تسوّت عبر الـwebhook أو التحصيل الإداري) —
        // تُقرأ من جديد: المحمَّلة أعلاه سبقت التسوية فتبقى «غير مدفوعة» ولو سدّدها الخطّاف للتوّ
        $consult->load('invoice');
        if ($consult->invoice?->paid) {
            PaymentReconciler::settleDomain($consult->invoice, $request->user()->name);

            return $back()->with('success', 'تم تأكيد الدفع — سوف يتم تحديد موعد جلستك مع المستشار المختص ويصلك إشعار به.');
        }

        // الـwebhook مصدر الحقيقة؛ إن خُصم المبلغ سيُحدَّث تلقائياً
        return $back()->with('error', 'تعذّر تأكيد الدفع. إن كان قد خُصم فسيُحدَّث تلقائياً، أو حاول مجدداً.');
    }

    // اختيار موعد الاستشارة بعد السداد (محظور قبل الدفع) → يُنشئ الموعد ويؤكّد المسار مع أقفال التزامن
    public function schedule(Request $request, Consult $consult): RedirectResponse
    {
        abort_unless($consult->user_id === $request->user()->id, 403);

        // **العميل لا يختار موعد جلسته** (قرار المالك 2026-09-14): يحدّده المكتب ويصله إشعارٌ به.
        // المسار باقٍ ليتلقّى من فتح صفحةً قديمة ردّاً مفهوماً لا خطأً مجهولاً.
        abort(422, 'يحدّد المكتب موعد جلستك مع المستشار المختص، ويصلك إشعارٌ وبريد بالموعد فور تحديده.');
    }

    /**
     * تقرير الاستشارة PDF — بنفس تصميم بطاقة .cf المرجعية، مُصيَّر فعلياً عبر Browsershot
     * (كروم مخفي حقيقي) لا تحويل صورة/محاكاة. ببيانات حقيقية من سجلّ الاستشارة فقط.
     */
    public function report(Request $request, Consult $consult): \Symfony\Component\HttpFoundation\Response
    {
        $user = $request->user();

        // كان الشرط `|| isEmployee() || isLawyer()` يُلغي ما قبله، فأي موظف أو محامٍ غير مسنَد
        // يسحب تقرير أي عميل. وشرط الإسناد كان ميتاً أصلاً: لا عمود lawyer_id على consults.
        // نفس نمط ExecFlowController::downloadDocument: صلاحية صريحة + عزل المحامي بإسناده.
        // الموظف يرى سجلات المكتب عمداً (مكتب واحد بعد إزالة الفروع) لكن بصلاحيته؛
        // والمحامي معزول بإسناده وحده — وهو العزل الذي كان مكسوراً هنا.
        $isOwner = $consult->user_id === $user->id;
        $isAssignedLawyer = $user->isLawyer() && $consult->assigned_lawyer_id === $user->id;
        $isPermittedEmployee = $user->isEmployee() && $user->can(Permissions::RECEIVE_CONSULTS);

        abort_unless($isOwner || $isAssignedLawyer || $isPermittedEmployee || $user->isAdmin(), 403);

        $html = ReportPrint::html(ConsultReport::doc($consult, $request->user()->name));

        return PdfRenderer::render($html, $consult->ref.'.pdf');
    }

    // غرفة الجلسة المرئية للعميل — تضمين Zoom داخل المنصّة (?ref=CN-…)
    public function room(Request $request): Response|RedirectResponse
    {
        $consult = Consult::with(['user', 'assignedLawyer'])->where('ref', (string) $request->query('ref'))->firstOrFail();
        abort_unless($consult->user_id === $request->user()->id, 403);
        // السبب الحقيقيّ من المصدر الواحد (`joinBlocker` عبر `RoomDetails::entryBlocker`) — «انتهت»
        // و«فاتت» و«لم تُفتح بعد» بنصّها نفسه في الغرف الأربع ونقطة توقيع Zoom
        if (($why = RoomDetails::entryBlocker($consult, $request->user())) !== null) {
            return RoomDetails::refuse($request, $consult, $why, 403);
        }

        return Inertia::render('videoroom', [
            // عقد الغرفة — والخاصيّتان القديمتان باقيتان حتى تنتقل الواجهة إليه
            'room' => RoomDetails::for($consult, $request->user()),
            'consult' => $consult->toClientCard(),
            'selfName' => $request->user()->name,
        ]);
    }

    /**
     * طلب العميل إعادة جدولة استشارته الفائتة — كانت الواجهة تقول «تواصل مع المكتب
     * لإعادة الجدولة» نصاً بلا أي زرّ ولا قناة. يوثَّق بسجل التدقيق ويُشعَر المحامي
     * المسند والإدارة؛ إعادة الجدولة الفعلية تبقى قرار المكتب (consults.reschedule).
     */
    /**
     * **العميل يطلب تغيير موعده** — القادمَ قبل ٢٤ ساعة، أو الفائت. (قرار المالك 2026-09-25)
     *
     * الطلب **حالةٌ معلّقة** (`reschedule_requested_at`) لا رسالةٌ عابرة: يُرسَل مرّةً، ويرى
     * العميل «قيد المعالجة»، ويراه الطاقم على الاستشارة حتى يُعيد جدولتها (فيُقضى) أو يرفضه
     * بسبب. كان يُرسَل مرّاتٍ بلا حدّ وكلُّ مرّةٍ تُنبّه الإدارة كلّها.
     *
     * والقرار كلّه في `Consult::rescheduleRequestBlocker` — الزرّ يقرأ منه ما يقرؤه الخادم هنا.
     */
    public function rescheduleRequest(Request $request, Consult $consult): RedirectResponse
    {
        abort_unless($consult->user_id === $request->user()->id, 403);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        if (($blocker = $consult->rescheduleRequestBlocker()) !== null) {
            abort(422, $blocker);
        }

        $note = trim((string) ($data['note'] ?? ''));
        $consult->forceFill([
            'reschedule_requested_at' => now(),
            'reschedule_request_note' => $note !== '' ? $note : null,
        ]);
        $consult->logAudit($request->user()->name, 'طلب تغيير الموعد', $consult->whenLabel(), $note !== '' ? $note : 'طلب العميل موعداً آخر');
        $consult->save();

        $message = "طلب العميل تغيير موعد الاستشارة ({$consult->ref})".($note !== '' ? " — «{$note}»" : '').'. أعِد جدولتها أو ارفض الطلب من شاشة الاستشارات.';
        BookingStaff::notify('cal', 't-amber', $message);
        if ($consult->assigned_lawyer_id) {
            Notify::send($consult->assigned_lawyer_id, 'cal', 't-amber', $message);
        }

        return back()->with('flash', 'أُرسل طلبك للمكتب — سيتواصل معك بموعدٍ جديد، ويبقى موعدك الحاليّ قائماً حتى ذلك.');
    }

    /**
     * **بابُ رفع ما طلبه المكتب.** حين تقف الاستشارة عند «بانتظار استكمال البيانات»
     * يُطالَب الموكّل بمستندات، ومكانُ رفعها محادثةُ تذكرته (`tickets.attach`) حيث
     * يراها الفريق. ورقمُ التذكرة لا يُرسَل في بطاقة العميل (عقدُ
     * `ClientConsultCardContractTest`)، فيحلّه الخادم هنا بعد التحقّق من الملكيّة.
     * وما لا تذكرة له يذهب إلى «مستنداتي» — لا طريقَ مسدود.
     */
    public function documents(Request $request, Consult $consult): RedirectResponse
    {
        abort_unless($consult->user_id === $request->user()->id, 403);

        $ticket = $consult->ticket;

        return $ticket
            ? redirect()->route('tickets.show', $ticket)
            : redirect()->route('documents');
    }
}
