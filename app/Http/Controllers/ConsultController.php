<?php

namespace App\Http\Controllers;

use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Models\Consult;
use App\Services\LegalAiService;
use App\Services\MoyasarService;
use App\Support\AppointmentCard;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use App\Support\Live;
use App\Support\PaymentReconciler;
use App\Support\TicketJourney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

use Illuminate\Support\Facades\DB;

/**
 * استشارات العميل — «استشاراتي» (يطابق myConsultsView) وغرفة الجلسة المرئية،
 * ودورة الحجز المطابقة للتصميم: دفع الفاتورة عبر ميسّر (بوّابة حقيقيّة) ثم اختيار الموعد بعد السداد.
 */
class ConsultController extends Controller
{
    // قائمة استشارات العميل الحالي
    public function index(Request $request): Response
    {
        $consults = Consult::with('user')
            ->where('user_id', $request->user()->id)
            ->latest('id')->get()
            ->map(fn (Consult $c) => $c->toClientCard());

        return Inertia::render('myconsults', [
            'consults' => $consults,
        ]);
    }

    // دفع فاتورة الاستشارة عبر بوّابة ميسّر → يعيد التوجيه لصفحة الدفع المستضافة.
    // التأكيد عبر webhook/callback (مصدر الحقيقة)؛ لا دفع بلا بوّابة مهيّأة.
    public function pay(Request $request, Consult $consult): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($consult->user_id === $request->user()->id, 403);
        abort_unless($consult->status === 'بانتظار السداد', 422, 'لا يوجد مبلغ مستحق للسداد على هذه الاستشارة.');
        abort_unless(app(MoyasarService::class)->isConfigured(), 503, 'بوّابة الدفع غير مهيّأة.');

        // رابط العودة من أصل الطلب نفسه (لا APP_URL) — فيعود المتصفّح لنفس النطاق وتبقى الجلسة صالحة
        $callback = $request->getSchemeAndHttpHost().route('consults.pay.callback', $consult, absolute: false);
        $url = ConsultBooking::initiatePayment($consult, $callback);
        if ($url === null) {
            return back()->with('error', 'تعذّر بدء الدفع حالياً، حاول بعد قليل.');
        }

        // يعيد Inertia توجيه المتصفّح لصفحة ميسّر (X-Inertia-Location) — الاستشارة تبقى بانتظار السداد حتى التأكيد
        return Inertia::location($url);
    }

    // العودة من صفحة ميسّر — تحقّق خادميّ صارم (لا يُوثَق بمعطيات الـURL): يُعاد جلب الدفعة والتحقّق منها.
    public function payCallback(Request $request, Consult $consult): RedirectResponse
    {
        abort_unless($consult->user_id === $request->user()->id, 403);

        $paymentId = (string) $request->query('id', '');
        $payment = $paymentId !== '' ? app(MoyasarService::class)->fetchPayment($paymentId) : null;

        // اربط الدفعة بفاتورة هذه الاستشارة تحديدًا (لا تسوية دفعة تخصّ فاتورة أخرى)
        $ref = $consult->invoice?->gateway_ref;
        $belongs = $payment !== null && (
            ($ref !== null && (string) ($payment['invoice_id'] ?? '') === (string) $ref) ||
            ($ref === null && (string) ($payment['metadata']['invoice_number'] ?? '') === (string) ($consult->invoice?->number ?? ''))
        );

        if ($belongs && PaymentReconciler::settle($payment, 'callback')) {
            return redirect()->route('myconsults')->with('success', 'تم تأكيد الدفع — اختر الآن موعد الجلسة.');
        }

        // إن كانت الفاتورة عُلّمت كمدفوعة أصلًا (تسوّت عبر الـwebhook أو التحصيل الإداري):
        if ($consult->invoice?->paid) {
            PaymentReconciler::settleDomain($consult->invoice, $request->user()->name);

            return redirect()->route('myconsults')->with('success', 'تم تأكيد الدفع — اختر الآن موعد الجلسة.');
        }

        // الـwebhook مصدر الحقيقة؛ إن خُصم المبلغ سيُحدَّث تلقائياً
        return redirect()->route('myconsults')->with('error', 'تعذّر تأكيد الدفع. إن كان قد خُصم فسيُحدَّث تلقائياً، أو حاول مجدداً.');
    }

    // اختيار موعد الاستشارة بعد السداد (محظور قبل الدفع) → يُنشئ الموعد ويؤكّد المسار مع أقفال التزامن
    public function schedule(Request $request, Consult $consult): RedirectResponse
    {
        abort_unless($consult->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        $startsAt = Carbon::parse($data['date'].' '.$data['time']);
        $specialty = $consult->specialty ?: (string) ($consult->ticket?->department ?? '');
        $subject = $consult->subject ?: ($consult->ticket?->type ?? null);

        $eventsToBroadcast = [];

        DB::transaction(function () use ($consult, $startsAt, $specialty, $subject, $data, &$eventsToBroadcast) {
            // إسناد ذكيّ خادميّ مع أقفال حماية التزامن لمنع الحجز المزدوج
            $lawyer = LawyerAvailability::assignLawyer($specialty, $subject, $startsAt, LawyerAvailability::slotMinutes());
            if ($lawyer === null) {
                throw ValidationException::withMessages(['time' => 'لا يوجد مستشار مختصّ متاح في هذا الوقت، فضلاً اختر وقتاً آخر.']);
            }

            $consultScheduled = ConsultBooking::schedule($consult, [
                'lawyer_id' => $lawyer->id,
                'starts_at' => $startsAt->toDateTimeString(),
                'duration' => LawyerAvailability::slotMinutes(),
                'day' => $startsAt->format('Y-m-d'),
                'time' => $data['time'],
            ]);

            if ($consultScheduled->ticket_id && ($ticket = $consultScheduled->ticket)) {
                $type = match ($consultScheduled->channel) {
                    'مرئية' => 'video',
                    'هاتفية' => 'phone',
                    default => 'office',
                };
                $card = AppointmentCard::render($consultScheduled, ConsultBooking::meta($type));

                $msg = $ticket->messages()->create([
                    'who' => 'ai',
                    'name' => LegalAiService::AGENT_NAME,
                    'role' => 'مواعيد',
                    'body' => $card,
                    'time_label' => now()->format('h:i').' '.(now()->hour < 12 ? 'ص' : 'م'),
                ]);

                $ticket->update([
                    'status' => 'موعد مؤكد',
                    'tone' => TicketJourney::toneFor('موعد مؤكد'),
                    'last_message' => 'تم تأكيد موعد الاستشارة: '.$consultScheduled->day.' · '.$consultScheduled->time,
                    'date_label' => 'الآن',
                ]);

                $eventsToBroadcast[] = new TicketMessageBroadcast($msg);
                $eventsToBroadcast[] = new TicketStatusBroadcast($ticket);
            }
        });

        if (! empty($eventsToBroadcast)) {
            DB::afterCommit(function () use ($eventsToBroadcast) {
                foreach ($eventsToBroadcast as $ev) {
                    Live::push($ev);
                }
            });
        }

        return back()->with('flash', 'تم تأكيد موعد استشارتك.');
    }

    // غرفة الجلسة المرئية للعميل — تضمين Zoom داخل المنصّة (?ref=CN-…)
    public function room(Request $request): Response
    {
        $consult = Consult::with('user')->where('ref', $request->query('ref'))->firstOrFail();
        abort_unless($consult->user_id === $request->user()->id, 403);

        return Inertia::render('videoroom', [
            'consult' => $consult->toClientCard(),
            'selfName' => $request->user()->name,
        ]);
    }
}
