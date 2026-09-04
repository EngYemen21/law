<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Role;
use App\Events\MeetingStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Mail\MeetInviteMail;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Services\MailService;
use App\Support\Audit;
use App\Support\ClientDirectory;
use App\Support\LawyerAvailability;
use App\Support\Live;
use App\Support\MeetInvitation;
use App\Support\Notify;
use App\Support\ReferenceNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * طلبات الاجتماعات لدى المكتب (يطابق meetReqsView + sendMeetInvite/mrCancel):
 * إرسال الدعوات للعملاء المسجّلين ومتابعة مراحل MR_FLOW.
 */
class MeetRequestController extends Controller
{
    public function index(Request $request): Response
    {
        // عزل بحسب المُرسِل (المحامي/الموظف): كلٌّ يرى دعواته التي أرسلها؛ الإدارة العليا ترى الكل للإشراف.
        $query = MeetRequest::with('user')->latest('id');
        if ($request->user()->role !== Role::Admin) {
            $query->where('sent_by_id', $request->user()->id);
        }

        return Inertia::render($this->prefix($request).'/meetreqs', [
            'requests' => $query->get()->map(fn (MeetRequest $r) => $r->toCard()),
            'clients' => ClientDirectory::list(),
            'lawyers' => User::where('role', Role::Lawyer)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            // إن كان المُنشئ محاميًا: يُثبَّت هو المحامي المسؤول (لا يختار غيره)
            'selfLawyerId' => $request->user()->role === Role::Lawyer ? $request->user()->id : null,
        ]);
    }

    // إرسال دعوة اجتماع لعميل مسجّل (يطابق sendMeetInvite) — تصل لإشعاراته ودعواته
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'lawyer_id' => ['required', 'integer', 'exists:users,id'],
            'service' => ['nullable', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:استشارة مرئية,استشارة حضورية,استشارة هاتفية'],
            'case_ref' => ['nullable', 'string', 'max:120'],
            'day' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'string', 'date_format:H:i'],
            'duration' => ['required', 'integer', 'in:30,45,60,90,120'],
        ]);

        $client = User::findOrFail($data['client_id']);
        abort_unless($client->role === Role::Client, 422);

        // المحامي المُنشئ يُسنِد نفسه حصراً؛ الموظف/الإدارة يختار المحامي المسؤول
        $lawyerId = $request->user()->role === Role::Lawyer ? $request->user()->id : (int) $data['lawyer_id'];
        $lawyer = User::find($lawyerId);
        abort_unless($lawyer && $lawyer->role === Role::Lawyer, 422);

        // موعد مستقبلي فقط (يحمي حالة «اليوم» بوقت مضى) + منع الحجز المزدوج للمحامي
        $startsAt = Carbon::createFromFormat('Y-m-d H:i', $data['day'].' '.$data['time']);
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['time' => 'لا يمكن اختيار موعد ماضٍ — اختر وقتاً لاحقاً.']);
        }
        $start = $startsAt->hour * 60 + $startsAt->minute;
        $end = $start + (int) $data['duration'];
        foreach ($this->busy($lawyer->id, $data['day']) as [$s, $e]) {
            if ($start < $e && $s < $end) {
                throw ValidationException::withMessages(['time' => 'هذا الموعد محجوز للمحامي — اختر وقتاً آخر، أو أحِل الطلب للإدارة العليا لإسناد محامٍ مختصّ آخر.']);
            }
        }

        $req = MeetRequest::create([
            'user_id' => $client->id,
            'ref' => ReferenceNumber::next(MeetRequest::class, 'ref', 'MR'),
            'service' => trim($data['service'] ?? '') ?: 'استشارة',
            'type' => $data['type'],
            'case_ref' => ($data['case_ref'] ?? '') ?: null,
            'day' => $data['day'],
            'time' => $data['time'],
            'duration_min' => (int) $data['duration'],
            'assigned_lawyer_id' => $lawyer->id,
            'sent_by' => $request->user()->name.' ('.$request->user()->role->label().')',
            'sent_by_id' => $request->user()->id,
        ]);

        // بوّابة النشر (قرار صاحب المنتج): دعوة الموظف/المحامي تمرّ بموافقة الإدارة العليا
        // قبل النشر — لا Zoom ولا اجتماع ولا إشعار للعميل حتى الموافقة (STAGE_SENT).
        // دعوة الإدارة نفسها تُنشر فوراً (موافقتها ضمنية).
        // (تأكيد العميل يبقى ملغى: الموافقة تلد الدعوة مؤكَّدة مباشرة.)
        if ($request->user()->role === Role::Admin) {
            $meeting = MeetInvitation::schedule($req, $client);
            MeetInvitation::announce($req, $meeting, $client);
        } else {
            $this->notifyAdmins("دعوة اجتماع جديدة ({$req->ref}) من {$req->sent_by} بانتظار موافقتكم — {$req->day} · {$req->time}.");
        }

        return back();
    }

    /**
     * موافقة الإدارة على دعوة معلّقة ونشرها: تُنشأ جلسة Zoom والاجتماع ويُشعر العميل.
     * هذا هو ما كان يقع لحظة الإرسال — نُقل خلف بوّابة الاعتماد بقرار صاحب المنتج.
     */
    public function approve(Request $request, MeetRequest $meetRequest): RedirectResponse
    {
        abort_unless($meetRequest->stage === MeetRequest::STAGE_SENT, 422, 'الموافقة متاحة للدعوات المعلّقة (بانتظار موافقة الإدارة) فقط.');

        $client = $meetRequest->user;
        abort_unless($client !== null, 422, 'عميل الدعوة غير موجود.');

        $meeting = MeetInvitation::schedule($meetRequest, $client);
        MeetInvitation::announce($meetRequest, $meeting, $client);

        Audit::log(
            action: 'اعتماد دعوة اجتماع',
            description: "اعتمدت الإدارة ({$request->user()->name}) دعوة الاجتماع {$meetRequest->ref} المرسلة من {$meetRequest->sent_by} ونُشرت للعميل {$client->name}.",
            category: 'اجتماعات',
            auditable: $meeting,
            auditableRef: $meetRequest->ref,
        );

        // إشعار المُرسِل بأن دعوته اعتُمدت ونُشرت
        if ($meetRequest->sent_by_id) {
            Notify::send($meetRequest->sent_by_id, 'check', 't-green', "وافقت الإدارة على دعوة الاجتماع ({$meetRequest->ref}) ونُشرت للعميل.");
        }

        return back()->with('flash', "تمت الموافقة على الدعوة {$meetRequest->ref} ونشرها.");
    }

    /** إشعار كل حسابات الإدارة العليا (نفس نمط TicketController عند فتح تذكرة). */
    private function notifyAdmins(string $message): void
    {
        foreach (User::where('role', Role::Admin)->get() as $admin) {
            Notify::send($admin->id, 'video', 't-amber', $message);
        }
    }

    /**
     * المواعيد المحجوزة لمحامٍ في يوم (لمنتقي المواعيد): فترات [بداية، نهاية] بصيغة HH:MM.
     * يجمع اجتماعات المحامي واستشاراته ودعواته المعلّقة في ذلك اليوم.
     */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lawyer_id' => ['required', 'integer', 'exists:users,id'],
            'day' => ['required', 'date_format:Y-m-d'],
        ]);

        $fmt = fn (int $min) => sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
        $intervals = $this->busy((int) $data['lawyer_id'], $data['day']);

        // الفترات الماضية محجوبة خادمياً — كان الحجب بساعة متصفّح العميل وحدها (توقيت مختلف يفتح فترات ماضية)
        if (now()->isSameDay(Carbon::parse($data['day']))) {
            $intervals[] = [0, now()->hour * 60 + now()->minute];
        }

        $busy = array_map(fn ($iv) => [$fmt($iv[0]), $fmt($iv[1])], $intervals);

        return response()->json(['busy' => $busy]);
    }

    /**
     * فترات انشغال المحامي — تفويض للمصدر الواحد.
     *
     * كان هنا تنفيذ ثانٍ يقرأ Meeting + Consult + MeetRequest بينما
     * LawyerAvailability::isBusy يقرأ Appointment وحده، فيتناقض الحارسان: موعد يُجدول
     * فوق اجتماع لأن مودال الجدولة يقرأ المصدر الأضيق. المنطق كلّه انتقل إلى
     * LawyerAvailability::busyIntervals ويقرأ الأربعة.
     *
     * @return array<int, array{0:int,1:int}>
     */
    private function busy(int $lawyerId, string $day): array
    {
        return LawyerAvailability::busyIntervals($lawyerId, $day);
    }

    // إعادة إرسال دعوة منتهية الصلاحية بموعد جديد — كانت الدعوة المنتهية طريقاً مسدوداً بلا أي إجراء
    public function resend(Request $request, MeetRequest $meetRequest): RedirectResponse
    {
        $this->guardOwner($request, $meetRequest);
        abort_unless($meetRequest->stage === MeetRequest::STAGE_EXPIRED, 422, 'إعادة الإرسال متاحة للدعوات المنتهية الصلاحية فقط.');

        $data = $request->validate([
            'day' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'string', 'date_format:H:i'],
        ]);

        // نفس حراس الإرسال الأول: موعد مستقبلي + منع تعارض حجوزات المحامي
        $startsAt = Carbon::createFromFormat('Y-m-d H:i', $data['day'].' '.$data['time']);
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['time' => 'لا يمكن اختيار موعد ماضٍ — اختر وقتاً لاحقاً.']);
        }
        if ($meetRequest->assigned_lawyer_id) {
            $start = $startsAt->hour * 60 + $startsAt->minute;
            $end = $start + (int) ($meetRequest->duration_min ?: 60);
            foreach ($this->busy($meetRequest->assigned_lawyer_id, $data['day']) as [$s, $e]) {
                if ($start < $e && $s < $end) {
                    throw ValidationException::withMessages(['time' => 'هذا الموعد محجوز للمحامي — اختر وقتاً آخر، أو أحِل الطلب للإدارة العليا لإسناد محامٍ مختصّ آخر.']);
                }
            }
        }

        // نفس بوّابة الإرسال الأول: إعادة إرسال الموظف/المحامي تعود لموافقة الإدارة،
        // وإعادة إرسال الإدارة تُنشر فوراً (وتُحدِّث موعد الاجتماع القائم عبر schedule).
        if ($request->user()->role === Role::Admin) {
            $meetRequest->update(['day' => $data['day'], 'time' => $data['time']]);
            $client = $meetRequest->user;
            abort_unless($client !== null, 422, 'عميل الدعوة غير موجود.');
            $meeting = MeetInvitation::schedule($meetRequest, $client);

            Notify::send($meetRequest->user_id, 'video', 't-blue', "أُعيد جدولة الاجتماع ({$meetRequest->ref}) بموعد جديد: {$meetRequest->day} · {$meetRequest->time} — تجده في قسم الاجتماعات.");
            app(MailService::class)->send($client, new MeetInviteMail($meetRequest->fresh()));
        } else {
            $meetRequest->update([
                'day' => $data['day'],
                'time' => $data['time'],
                'stage' => MeetRequest::STAGE_SENT, // معلّقة من جديد — بانتظار موافقة الإدارة
            ]);
            $this->notifyAdmins("دعوة اجتماع معادة ({$meetRequest->ref}) من {$meetRequest->sent_by} بانتظار موافقتكم — {$meetRequest->day} · {$meetRequest->time}.");
        }

        return back();
    }

    /**
     * إلغاء دعوة لم تُنفَّذ بعد — «أُلغيت» سجلاً تاريخياً بدل الحذف الصلب.
     *
     * ⚠️ كان الشرط `=== STAGE_SENT` وحدها. وبعد أن صارت الدعوة تُولَد مؤكَّدة (stage=1)
     * **توقّف الإلغاء صامتاً**: الزرّ يُنقر، الطلب يعود 302، ولا شيء يتغيّر. نفس صنف فخّ
     * حارس الحجز المزدوج. `< STAGE_EXECUTED` يشمل 0 و1 ويستثني المنفَّذة والمعتمدة
     * والمنتهية والملغاة (كلّها ≥ 2) — فلا تُلغى جلسة انعقدت فعلاً.
     */
    public function cancel(Request $request, MeetRequest $meetRequest): RedirectResponse
    {
        $this->guardOwner($request, $meetRequest);
        if ($meetRequest->stage < MeetRequest::STAGE_EXECUTED) {
            $meetRequest->update(['stage' => MeetRequest::STAGE_CANCELLED]);
            Notify::send($meetRequest->user_id, 'info', 't-grey', "أُلغيت دعوة الاجتماع ({$meetRequest->ref}) — «{$meetRequest->service}».");
        }

        return back();
    }

    // بدء تنفيذ الجلسة (مرحلة 2) — يفتح المكتب Zoom ويُعلَّم الاجتماع «جارٍ»
    public function start(Request $request, MeetRequest $meetRequest): RedirectResponse
    {
        $this->guardOwner($request, $meetRequest);
        if ($meetRequest->stage === MeetRequest::STAGE_CONFIRMED) {
            $meetRequest->update(['stage' => MeetRequest::STAGE_EXECUTED]);
            // مطابقة نمط MeetingController: علَم «جارٍ الآن» + بثّ لحظي لشاشة العميل
            if ($meeting = $meetRequest->meeting) {
                $meeting->update(['status' => 'جارٍ']);
                Live::push(new MeetingStatusBroadcast($meeting->fresh()));
            }
        }

        return back();
    }

    /** يمنع (403) تصرّف غير المُرسِل في دعوة ليست له؛ الإدارة العليا مستثناة (إشراف). */
    private function guardOwner(Request $request, MeetRequest $meetRequest): void
    {
        if ($request->user()->role === Role::Admin) {
            return;
        }

        abort_unless($meetRequest->sent_by_id === $request->user()->id, 403);
    }

    private function prefix(Request $request): string
    {
        return match ($request->user()->role) {
            Role::Employee => 'employee',
            Role::Lawyer => 'lawyer',
            default => 'admin',
        };
    }
}
