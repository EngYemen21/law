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
use App\Support\Mask;
use App\Support\PaymentReconciler;
use App\Support\ReportPrint;
use App\Support\TicketJourney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Browsershot\Browsershot;

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

        // بعد الدفع يعود العميل لدردشة التذكرة (حيث لوحة اختيار الموعد)؛ وإن لم ترتبط بتذكرة فإلى «استشاراتي».
        $back = fn (): RedirectResponse => $consult->ticket_id
            ? redirect()->route('tickets.show', $consult->ticket)
            : redirect()->route('myconsults');

        $paymentId = (string) $request->query('id', '');
        $payment = $paymentId !== '' ? app(MoyasarService::class)->fetchPayment($paymentId) : null;

        // اربط الدفعة بفاتورة هذه الاستشارة تحديدًا (لا تسوية دفعة تخصّ فاتورة أخرى)
        $ref = $consult->invoice?->gateway_ref;
        $belongs = $payment !== null && (
            ($ref !== null && (string) ($payment['invoice_id'] ?? '') === (string) $ref) ||
            ($ref === null && (string) ($payment['metadata']['invoice_number'] ?? '') === (string) ($consult->invoice?->number ?? ''))
        );

        if ($belongs && PaymentReconciler::settle($payment, 'callback')) {
            return $back()->with('success', 'تم تأكيد الدفع — اختر الآن موعد الجلسة.');
        }

        // إن كانت الفاتورة عُلّمت كمدفوعة أصلًا (تسوّت عبر الـwebhook أو التحصيل الإداري):
        if ($consult->invoice?->paid) {
            PaymentReconciler::settleDomain($consult->invoice, $request->user()->name);

            return $back()->with('success', 'تم تأكيد الدفع — اختر الآن موعد الجلسة.');
        }

        // الـwebhook مصدر الحقيقة؛ إن خُصم المبلغ سيُحدَّث تلقائياً
        return $back()->with('error', 'تعذّر تأكيد الدفع. إن كان قد خُصم فسيُحدَّث تلقائياً، أو حاول مجدداً.');
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
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['time' => 'لا يمكن اختيار موعد في الماضي، فضلاً اختر وقتاً لاحقاً.']);
        }
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

    /**
     * تقرير الاستشارة PDF — بنفس تصميم بطاقة .cf المرجعية، مُصيَّر فعلياً عبر Browsershot
     * (كروم مخفي حقيقي) لا تحويل صورة/محاكاة. ببيانات حقيقية من سجلّ الاستشارة فقط.
     */
    public function report(Request $request, Consult $consult): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($consult->user_id === $request->user()->id, 403);

        $payLabel = $consult->paid_at !== null ? 'مدفوعة' : ($consult->priced_at !== null ? 'بانتظار السداد' : 'بانتظار التسعير من الإدارة');
        $place = $consult->channel === 'حضورية'
            ? ($consult->branch ?: '—')
            : ($consult->channel === 'مرئية' ? 'اجتماع إلكتروني' : 'مكالمة هاتفية');

        $nextStep = match (true) {
            $consult->priced_at === null => 'بانتظار تحديد السعر من الإدارة',
            $consult->paid_at === null => 'سداد الفاتورة عبر ميسّر',
            $consult->session === 'منتهية' => $consult->summary ? 'راجع ملخص الاستشارة أعلاه' : 'بانتظار إصدار ملخص الاستشارة',
            $consult->session === 'جلسة جارية' => 'الجلسة قائمة الآن',
            default => 'حضور الجلسة في الموعد المحدّد',
        };

        $html = ReportPrint::html([
            'title' => 'تقرير الاستشارة القانونية',
            'subtitle' => 'نسخة العميل',
            'ref' => $consult->ref,
            'blocks' => [
                [
                    'title' => '١. بيانات الاستشارة',
                    'cellRows' => [
                        [['رقم الاستشارة', $consult->ref], ['نوع الاستشارة', $consult->channel], ['التخصّص', $consult->specialty ?: '—'], ['الموعد', $consult->when_label ?: '—']],
                        [['المكان', $place], ['حالة الجلسة', $consult->session ?: '—'], ['حالة السداد', $payLabel], ['رقم الفاتورة', $consult->invoice?->number ?: '—']],
                    ],
                ],
                [
                    ['title' => '٢. بياناتك', 'cellRows' => [[['اسم العميل', $request->user()->name], ['الحالة', 'عميل نشط']]]],
                    ['title' => '٣. مقدّم الخدمة', 'cellRows' => [[['الجهة', 'المكتب القانوني'], ['المحامي المسؤول', Mask::lawyer($consult->lawyer)]]]],
                ],
                ['title' => '٤. ملخص الاستشارة', 'lines' => $consult->summary ?: 'يُعدّ الفريق القانوني ملخص استشارتك، وسيصلك فور اعتماده من الإدارة.'],
                [
                    'title' => '٥. الفاتورة والسداد',
                    'cellRows' => [[
                        ['رسوم الاستشارة', $consult->price ? $consult->price.' ر.س' : '—'],
                        ['الضريبة (١٥٪)', $consult->vat ? $consult->vat.' ر.س' : '—'],
                        ['الإجمالي', $consult->total ? $consult->total.' ر.س' : '—'],
                        ['حالة السداد', $payLabel],
                    ]],
                ],
                ['title' => '٦. الإجراء القادم', 'chips' => [$nextStep]],
            ],
            'approval' => [
                'qrSeed' => $consult->ref,
                'rows' => [
                    ['الجهة', 'النظام الإداري لمكاتب المحاماة'],
                    ['حالة الاستشارة', $consult->status],
                    ['تاريخ الطباعة', now()->format('Y-m-d')],
                ],
            ],
            'note' => 'هذا التقرير يلخّص استشارتك القانونية ولا يُعدّ بذاته مرافعة أو مستنداً قضائياً. للاستفسار يمكنك فتح تذكرة من بوابتك.',
            'footer' => 'النظام الإداري لمكاتب المحاماة — نسخة العميل · صادرة إلكترونياً',
        ]);

        $pdf = Browsershot::html($html)
            ->setCustomTempPath(storage_path('app/browsershot-tmp'))
            ->setNodeModulePath(base_path('node_modules'))
            ->noSandbox()
            ->format('A4')
            ->showBackground()
            ->margins(12, 12, 12, 12)
            ->pdf();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$consult->ref.'.pdf"',
        ]);
    }

    // غرفة الجلسة المرئية للعميل — تضمين Zoom داخل المنصّة (?ref=CN-…)
    public function room(Request $request): Response
    {
        $consult = Consult::with('user')->where('ref', $request->query('ref'))->firstOrFail();
        abort_unless($consult->user_id === $request->user()->id, 403);
        abort_unless($consult->canJoin(), 403, 'لا يمكن دخول الجلسة إلا قبل موعدها بـ 5 دقائق.');

        return Inertia::render('videoroom', [
            'consult' => $consult->toClientCard(),
            'selfName' => $request->user()->name,
        ]);
    }
}
