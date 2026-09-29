<?php

namespace App\Http\Controllers\Employee;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Support\ConsultAppointments;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use App\Support\TicketJourney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * جدولة/حجز استشارة من الموظف نيابةً عن عميل — يحفظ حجزاً حقيقياً.
 * كل محامي المكتب (مكتب واحد بعد إزالة كيان الفرع).
 */
class ScheduleController extends Controller
{
    /**
     * شاشة «جدولة المواعيد» طُويت في التبويب الزمني الموحّد `/employee/calendar`،
     * وصفحتها (resources/js/pages/employee/schedule.tsx) صارت منظر «المواعيد» داخله —
     * بمودال الحجز نفسه وحرّاسه: رفض الماضي · LawyerAvailability::isBusy · ActiveLawyer ·
     * ربط ticket_no. slots() وstore() أدناه لم تُمسّا ويناديهما المودال مباشرةً.
     *
     * المسار يبقى ويُحوِّل — لا يُحذف: روابط محفوظة وإشعارات سابقة تشير إليه.
     *
     * الجسم السابق (محفوظ عمداً):
     *   return Inertia::render('employee/schedule', AppointmentBoard::data($request->user()));
     *
     * واللوحة تُبنى الآن في Employee\CalendarController::index من AppointmentBoard نفسها.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('employee.calendar');
    }

    /**
     * GET …/schedule/day-slots?date=YYYY-MM-DD&lawyer_ids[]=… — شرائح اليوم لكلّ مستشاري شبكة التفرّغ
     * (`LawyerAvailability::daySlotsForMany`). تقرؤها الشبكة لتُظهر الوقت الذي يشغله اجتماعٌ أو جلسة
     * محكمة — كانت تعرضه «احجز الآن» ثمّ يرفضه الخادم «مشغول». اطّلاعٌ لمن يرى التقويم.
     */
    public function daySlots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'lawyer_ids' => ['required', 'array', 'max:100'],
            'lawyer_ids.*' => ['integer'],
        ]);

        $ids = User::where('role', Role::Lawyer)->whereIn('id', $data['lawyer_ids'])->pluck('id')->map(fn ($id) => (int) $id)->all();

        return response()->json(['slots' => (object) LawyerAvailability::daySlotsForMany($ids, Carbon::parse($data['date'])->startOfDay())]);
    }

    /**
     * API: فترات محامٍ في يوم معيّن — يُعيد كل ساعة مع علامة مشغول/متاح.
     * GET /employee/schedule/slots?lawyer_id=X&date=YYYY-MM-DD
     */
    public function slots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lawyer_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);

        $slots = LawyerAvailability::slotsFor((int) $data['lawyer_id'], $data['date']);

        return response()->json(['slots' => $slots]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'consult_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'in:office,video,phone'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'string', 'date_format:H:i'],
            // المحامي اختياري؛ إن اختير يجب أن يكون نشطاً (يمنع تمرير عميل/موظف/إداري).
            'lawyer_id' => ['nullable', 'integer', new ActiveLawyer],
            // رقم التذكرة حين تُجدول من شاشة المحادثة — يحدّد استشارتها المدفوعة
            'ticket_no' => ['nullable', 'string', 'max:60'],
        ]);

        $client = User::where('role', Role::Client)->whereKey($data['client_id'])->firstOrFail();
        $startsAt = Carbon::parse($data['date'].' '.$data['time']);

        // أخطاء المُدخل قبل حالة الملفّ — كما كانت: وقتٌ مضى، أو محامٍ مختارٌ مشغول
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['time' => 'لا يمكن اختيار موعد في الماضي، فضلاً اختر وقتاً لاحقاً.']);
        }
        // دوام المكتب قبل انشغال المحامي — فالجمعة تُرفض «خارج الدوام» لا «مشغول» (ما لم تسمح الإدارة)
        ConsultBooking::officeHoursVerdict($startsAt, 'time');
        if (! empty($data['lawyer_id'])) {
            // القرار من `ConsultBooking::conflictVerdict` (جلسة محكمة، أو خيار الحجز المتداخل). ونفس صياغة
            // حارس دعوات الاجتماعات (قرار صاحب المنتج) — والإدارة نفسها لا تُحال إلى نفسها
            ConsultBooking::conflictVerdict((int) $data['lawyer_id'], $startsAt, LawyerAvailability::slotMinutes(), 'time', $request->user()->isAdmin()
                ? 'المحامي مشغول في هذا الوقت — اختر وقتاً آخر أو محامياً مختلفاً.'
                : 'المحامي مشغول في هذا الوقت — اختر وقتاً آخر أو محامياً مختلفاً، أو أحِل الطلب للإدارة العليا لإسناد محامٍ مختصّ آخر.');
        }

        /*
         * **الحجز على استشارةٍ مدفوعة لا حجزٌ «مدفوع مسبقاً»** (قرار المالك 2026-09-14).
         *
         * كان هذا المسار يُنشئ استشارةً جديدة بفاتورةٍ «مدفوعة» بلا دفعة ولا قيدٍ في الدفتر،
         * ويؤكّد الموعد ويرسله للعميل فوراً، ويعيد فتح تذكرةٍ مغلقة، وينشئ طلباً ثانياً فوق
         * طلبٍ قائم (ع٣، ع١٦، ع١٨). الآن: السداد أوّلاً، ثمّ يحجز الطاقم على الاستشارة المدفوعة
         * — الموظّف يقترح والإدارة تعتمد، والإدارة تنشر مباشرةً.
         */
        $consult = $this->payableConsult($client, $data['consult_id'] ?? null, $data['ticket_no'] ?? null);

        $input = [
            'date' => $data['date'],
            'time' => $data['time'],
            'lawyer_id' => $data['lawyer_id'] ?? null,
            'type' => $data['type'] ?? null,
        ];

        $isAdmin = $request->user()->isAdmin();
        $scheduled = $isAdmin
            ? ConsultAppointments::publish($consult, $request->user(), $input)
            : ConsultAppointments::propose($consult, $request->user(), $input);

        $message = ($isAdmin
            ? "تم تحديد موعد الاستشارة {$consult->ref} وإرساله للعميل {$client->name}."
            : "أُرسل موعد الاستشارة {$consult->ref} لاعتماد الإدارة قبل إرساله للعميل.")
            .$scheduled->notice();

        if ($request->expectsJson()) {
            return response()->json(['ref' => $consult->ref, 'message' => $message]);
        }

        return back()->with('flash', $message);
    }

    /**
     * **طلب استشارة نيابةً عن العميل** — لعميل المكتب الحاضر أو المتّصل.
     *
     * يسلك الرحلة نفسها: تسعير ← سداد (أو تحصيل يدويّ مقيَّد) ← حجز الطاقم. لا اختصار.
     */
    public function requestFor(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', 'string', 'in:office,video,phone'],
            'subject' => ['nullable', 'string', 'max:120'],
            'ticket_no' => ['nullable', 'string', 'max:60'],
        ]);

        $client = User::where('role', Role::Client)->whereKey($data['client_id'])->firstOrFail();
        $ticket = ! empty($data['ticket_no'])
            ? Ticket::where('number', $data['ticket_no'])->where('user_id', $client->id)->first()
            : null;

        if ($ticket !== null && ($why = TicketJourney::consultRequestBlocker($ticket))) {
            abort(422, $why);
        }
        abort_if(
            Consult::where('user_id', $client->id)
                ->when($ticket, fn ($q) => $q->where('ticket_id', $ticket->id))
                ->whereIn('status', Consult::PRE_SESSION_STATUSES)
                ->exists(),
            422,
            'يوجد طلب استشارة قائم لهذا العميل لم يكتمل حجزه.'
        );

        // النوع إلزاميّ دائماً، والموضوع يُمرَّر حين يُكتب فقط
        $consult = ConsultBooking::request(
            $client,
            ['type' => $data['type']] + (filled($data['subject'] ?? null) ? ['subject' => $data['subject']] : []),
            $ticket
        );

        $message = "أُنشئ طلب الاستشارة {$consult->ref} للعميل {$client->name} — بانتظار التسعير.";

        return $request->expectsJson()
            ? response()->json(['ref' => $consult->ref, 'message' => $message])
            : back()->with('flash', $message);
    }

    /**
     * الاستشارة المدفوعة بانتظار موعدها — المحدَّدة، أو استشارة التذكرة، أو الوحيدة للعميل.
     *
     * لا تخمين: إن كان للعميل أكثر من استشارةٍ مدفوعة ولم يُحدَّد أيّها، يُرفض الطلب بدل حجز
     * الموعد لأقدمها صامتاً — كان الموظّف يقصد الثانية فيُحجز للأولى.
     */
    private function payableConsult(User $client, ?int $consultId, ?string $ticketNo): Consult
    {
        $query = Consult::where('user_id', $client->id)->where('status', ConsultStatus::AwaitingSchedule->value);

        if ($consultId) {
            $query->whereKey($consultId);
        } elseif (! empty($ticketNo)) {
            $query->whereHas('ticket', fn ($q) => $q->where('number', $ticketNo));
        } elseif ((clone $query)->count() > 1) {
            throw ValidationException::withMessages([
                'consult_id' => 'للعميل أكثر من استشارة مدفوعة بانتظار موعد — اختر الاستشارة.',
            ]);
        }

        $consult = $query->orderBy('id')->first();

        if ($consult === null) {
            throw ValidationException::withMessages([
                'client_id' => 'لا توجد استشارة مدفوعة بانتظار موعدها لهذا العميل — يُطلب الحجز ويُسعَّر ويُسدَّد أوّلاً.',
            ]);
        }

        return $consult;
    }
}
