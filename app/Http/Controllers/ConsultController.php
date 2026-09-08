<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Jobs\EscalateUnassignedTicketJob;
use App\Models\Consult;
use App\Models\User;
use App\Services\LegalAiService;
use App\Services\MoyasarService;
use App\Support\AppointmentCard;
use App\Support\ConsultBooking;
use App\Support\ConsultReport;
use App\Support\LawyerAvailability;
use App\Support\Live;
use App\Support\Notify;
use App\Support\PaymentReconciler;
use App\Support\PdfRenderer;
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
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'string', 'date_format:H:i'],
        ]);

        $startsAt = Carbon::parse($data['date'].' '.$data['time']);
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['time' => 'لا يمكن اختيار موعد في الماضي، فضلاً اختر وقتاً لاحقاً.']);
        }
        $specialty = $consult->specialty ?: (string) ($consult->ticket?->department ?? '');
        $subject = $consult->subject ?: ($consult->ticket?->type ?? null);

        $eventsToBroadcast = [];
        $escalatedTo = null;

        DB::transaction(function () use ($consult, $startsAt, $specialty, $subject, $data, &$eventsToBroadcast, &$escalatedTo) {
            // إسناد ذكيّ خادميّ مع أقفال حماية التزامن لمنع الحجز المزدوج
            $lawyer = LawyerAvailability::assignLawyer($specialty, $subject, $startsAt, LawyerAvailability::slotMinutes());

            /*
             * **الحجز المتعذّر يُرفع إلى الإدارة — لا يُرفض.**
             *
             * كان الإخفاق يرمي خطأ تحقّقٍ للعميل وينتهي: عميلٌ **سدّد** ثمّ صُدّ،
             * والإدارة لا تعلم أنّ أحداً حاول. ورسالتُه كاذبة فوق ذلك — تقول «لا يوجد
             * مستشار **مختصّ**» بينما `rankedSpecialists` يسقط إلى **كلّ** المحامين
             * النشطين حين لا يطابق أحدٌ التخصّص. فالسبب الحقيقيّ أنّ الجميع مشغولون
             * في تلك الساعة (أو لا محاميَ نشطاً)، والتخصّص لا دخل له.
             *
             * فيُحجز الموعد ويُرفع الملفّ إلى أقدم إداريّ ليوزّعه — احتذاءً بسابقة
             * `EscalateUnassignedTicketJob` في التذاكر، بالوسم نفسه «الإدارة العليا».
             *
             * و`ConsultBooking::schedule` يحتمل غياب المحامي أصلاً: `guardNoConflict`
             * مشروطٌ به، والموعد يُنشأ بـ`lawyer_id = null`.
             */
            if ($lawyer === null) {
                $escalatedTo = User::where('role', Role::Admin)->orderBy('id')->first();

                // ولا إدارةَ بعد (تثبيتٌ جديد) ⇒ يبقى الرفض: لا يُترك ملفٌّ بلا مالك
                if ($escalatedTo === null) {
                    throw ValidationException::withMessages([
                        'time' => 'هذا الوقت لم يعد متاحاً، فضلاً اختر وقتاً آخر.',
                    ]);
                }

                $consult->logAudit(
                    'النظام',
                    'الإسناد',
                    '—',
                    'تعذّر الإسناد التلقائيّ — رُفع إلى الإدارة للتوزيع'
                );
                $consult->save();
            }

            $consultScheduled = ConsultBooking::schedule($consult, [
                'lawyer_id' => $lawyer?->id,
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

        /*
         * **التصعيد يُوسَم ويُشعَر بعد الالتزام — لا داخله.**
         *
         * الوسم بعد `schedule` لأنّها تكتب `lawyer` النائب النصّيّ (`المستشار القانوني`)
         * و`assigned_lawyer_id = null`؛ فالوسم هنا يجعل الملفّ **مملوكاً** لأقدم إداريّ
         * كما تفعل `EscalateUnassignedTicketJob` بالتذاكر — فلا يبقى بلا صاحبٍ يتصرّف.
         *
         * وبقفلٍ قصيرٍ وإعادة فحص: لو أُسنِد يدويّاً في اللحظة نفسها فلا يُكتب فوقه.
         */
        if ($escalatedTo !== null) {
            DB::transaction(function () use ($consult, $escalatedTo) {
                $locked = Consult::whereKey($consult->id)->lockForUpdate()->first();
                if ($locked === null || $locked->assigned_lawyer_id) {
                    return;
                }

                $locked->update([
                    'lawyer' => EscalateUnassignedTicketJob::SENIOR_LABEL,
                    'assigned_lawyer_id' => $escalatedTo->id,
                ]);
            });

            $consult->refresh();

            $notice = "استشارة ({$consult->ref}) حُجزت {$consult->when_label} ولم يتوفّر محامٍ في هذا الوقت — وزّعها على مستشارٍ من شاشة الاستشارات.";
            foreach (User::where('role', Role::Admin)->get() as $admin) {
                Notify::send($admin->id, 'cal', 't-amber', $notice);
            }

            // **والعميل لا يُوعَد بما لم يقع:** موعدُه مؤكَّد، ومستشارُه لم يُسنَد بعد.
            Notify::send(
                $consult->user_id,
                'cal',
                't-amber',
                "تم تأكيد موعد استشارتك ({$consult->ref}) {$consult->when_label} — ويُسنَد مستشارك قبل الجلسة ويصلك إشعار."
            );

            return back()->with('flash', 'تم تأكيد موعدك — ويُسنَد مستشارك قبل الجلسة ويصلك إشعار.');
        }

        return back()->with('flash', 'تم تأكيد موعد استشارتك.');
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
        $isPermittedEmployee = $user->isEmployee() && $user->can('استقبال الاستشارات');

        abort_unless($isOwner || $isAssignedLawyer || $isPermittedEmployee || $user->isAdmin(), 403);

        $html = ReportPrint::html(ConsultReport::doc($consult, $request->user()->name));

        return PdfRenderer::render($html, $consult->ref.'.pdf');
    }

    // غرفة الجلسة المرئية للعميل — تضمين Zoom داخل المنصّة (?ref=CN-…)
    public function room(Request $request): Response
    {
        $consult = Consult::with('user')->where('ref', $request->query('ref'))->firstOrFail();
        abort_unless($consult->user_id === $request->user()->id, 403);
        // رسالة مميّزة لكل حالة — «انتهت» توحي بمراجعة الملخص، و«لم يحن» تدعو للانتظار
        abort_unless($consult->canJoin(), 403, ($consult->session === 'منتهية' || $consult->isMissed())
            ? 'انتهت جلسة هذه الاستشارة — لم يعد الدخول متاحاً.'
            : 'لم يحن موعد الجلسة بعد — يُفعَّل الدخول قبل الموعد بـ5 دقائق.');

        return Inertia::render('videoroom', [
            'consult' => $consult->toClientCard(),
            'selfName' => $request->user()->name,
        ]);
    }

    /**
     * طلب العميل إعادة جدولة استشارته الفائتة — كانت الواجهة تقول «تواصل مع المكتب
     * لإعادة الجدولة» نصاً بلا أي زرّ ولا قناة. يوثَّق بسجل التدقيق ويُشعَر المحامي
     * المسند والإدارة؛ إعادة الجدولة الفعلية تبقى قرار المكتب (consults.reschedule).
     */
    public function rescheduleRequest(Request $request, Consult $consult): RedirectResponse
    {
        abort_unless($consult->user_id === $request->user()->id, 403);
        abort_unless(
            $consult->isMissed() || $consult->session === 'لم تُعقد' || $consult->status === 'لم يحضر',
            422,
            'طلب إعادة الجدولة متاح للجلسات الفائتة فقط.'
        );

        $consult->logAudit($request->user()->name, 'طلب إعادة الجدولة', '—', 'طلب العميل موعداً جديداً');
        $consult->save();

        $message = "طلب العميل إعادة جدولة الاستشارة الفائتة ({$consult->ref}) — حدّد موعداً جديداً من شاشة الاستشارات.";
        if ($consult->assigned_lawyer_id) {
            Notify::send($consult->assigned_lawyer_id, 'cal', 't-amber', $message);
        }
        foreach (User::where('role', Role::Admin)->get() as $admin) {
            Notify::send($admin->id, 'cal', 't-amber', $message);
        }

        return back()->with('flash', 'أُرسل طلبك للمكتب — سيتواصل معك فريقنا بموعد جديد قريباً.');
    }
}
