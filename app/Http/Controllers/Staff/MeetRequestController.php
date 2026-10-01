<?php

namespace App\Http\Controllers\Staff;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Domain\Journey\Transitions\Meeting\CancelMeeting;
use App\Domain\Journey\Transitions\Meeting\StartMeeting;
use App\Domain\Journey\Workflow;
use App\Enums\BusyKind;
use App\Enums\Role;
use App\Events\Journey\MeetingCancelled;
use App\Events\Journey\SessionEndedInSystem;
use App\Http\Controllers\Controller;
use App\Mail\MeetInviteMail;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Services\MailService;
use App\Support\Audit;
use App\Support\ClientDirectory;
use App\Support\LawyerAvailability;
use App\Support\MeetingTime;
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
    /** رسالة التعارض — واحدةٌ للإرسال الأوّل وإعادة الإرسال. */
    private const TAKEN = 'هذا الموعد محجوز للمحامي — اختر وقتاً آخر، أو أحِل الطلب للإدارة العليا لإسناد محامٍ مختصّ آخر.';

    public function index(Request $request): Response
    {
        // الموظّف يرى كلّ دعوات المكتب (قرار المالك 2026-09-14)، والمحامي ما أُسند إليه، والإدارة الكل.
        // (الإجراءات — إلغاء/بدء/إعادة إرسال — ما زالت محروسةً بالمُرسِل في `guardOwner`.)
        // `meeting` لنافذة الدخول (`canJoin`) ومرجع الغرفة في البطاقة — كان يُجلب لكلّ دعوةٍ على حدة (N+1)
        $query = MeetRequest::with(['user', 'meeting'])->latest('id');
        if ($request->user()->role === Role::Lawyer) {
            $query->where('assigned_lawyer_id', $request->user()->id);
        }

        return Inertia::render($this->prefix($request).'/meetreqs', [
            'requests' => $query->get()->map(fn (MeetRequest $r) => $r->toCard()),
            'clients' => ClientDirectory::list(),
            // النشطون وحدهم (`User::activeLawyers`) — القائمة نفسها التي يقبلها `store` بـ`ActiveLawyer`
            'lawyers' => User::activeLawyers()->orderBy('name')->get(['id', 'name'])
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
            // محامٍ **نشط** لا «أيّ مستخدم» — كان الموقوف يُسند إليه اجتماعٌ لا يستطيع دخوله
            'lawyer_id' => ['required', 'integer', new ActiveLawyer],
            'service' => ['nullable', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:استشارة مرئية,استشارة حضورية,استشارة هاتفية'],
            'case_ref' => ['nullable', 'string', 'max:120'],
            'day' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'string', 'date_format:H:i'],
            // لا «مدة» (قرار المالك 2026-09-26): الاجتماع ينتهي حين يُنهى، والتعارض بمسافة الحجز
        ]);

        $client = User::findOrFail($request->integer('client_id'));
        abort_unless($client->role === Role::Client, 422, 'الحساب المختار ليس حساب عميل.');

        // المحامي المُنشئ يُسنِد نفسه حصراً؛ الموظف/الإدارة يختار المحامي المسؤول
        $lawyerId = $request->user()->role === Role::Lawyer ? $request->user()->id : (int) $data['lawyer_id'];
        $lawyer = User::find($lawyerId);
        abort_unless($lawyer && $lawyer->role === Role::Lawyer, 422, 'المحامي المختار غير موجود أو ليس حساب محامٍ.');

        // موعد مستقبلي فقط (يحمي حالة «اليوم» بوقت مضى) + منع الحجز المزدوج للمحامي
        $startsAt = Carbon::createFromFormat('Y-m-d H:i', $data['day'].' '.$data['time']);
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['time' => 'لا يمكن اختيار موعد ماضٍ — اختر وقتاً لاحقاً.']);
        }
        // التعارض بمسافة الحجز من المصدر الواحد (`LawyerAvailability::isBusy`) — لا مدّةٍ يختارها النموذج
        if (LawyerAvailability::isBusy($lawyer->id, $startsAt)) {
            throw ValidationException::withMessages(['time' => self::TAKEN]);
        }

        $req = MeetRequest::create([
            'user_id' => $client->id,
            'ref' => ReferenceNumber::next(MeetRequest::class, 'ref', 'MR'),
            'service' => trim($data['service'] ?? '') ?: 'استشارة',
            'type' => $data['type'],
            'case_ref' => ($data['case_ref'] ?? '') ?: null,
            'day' => $data['day'],
            'time' => $data['time'],
            'assigned_lawyer_id' => $lawyer->id,
            'sent_by' => $request->user()->name.' ('.$request->user()->role->label().')',
            'sent_by_id' => $request->user()->id,
        ]);

        // بوّابة النشر (قرار صاحب المنتج): دعوة الموظف/المحامي تمرّ بموافقة الإدارة العليا
        // قبل النشر — لا Zoom ولا اجتماع ولا إشعار للعميل حتى الموافقة (STAGE_SENT).
        // دعوة الإدارة نفسها تُنشر فوراً (موافقتها ضمنية).
        // (تأكيد العميل يبقى ملغى: الموافقة تلد الدعوة مؤكَّدة مباشرة.)
        // **الرسالة من الخادم لا من الواجهة** (قرار المالك 2026-10-01): كانت الواجهة تقول للموظف/المحامي
        // «تم إرسال الدعوة وإشعارها إلى العميل» ودعوته لم تصل العميل بعد — تنتظر موافقة الإدارة.
        // الخادم وحده يعرف أيّ المسارين وقع، فيقول ما وقع فعلاً (`flash.success` ← `ServerFeedback`).
        if ($request->user()->role === Role::Admin) {
            $meeting = MeetInvitation::schedule($req, $client);
            MeetInvitation::announce($req, $meeting, $client);

            return back()->with('success', "تم إرسال الدعوة ({$req->ref}) ونشرها — وصل الإشعار إلى العميل: {$client->name}");
        }

        $this->notifyAdmins("دعوة اجتماع جديدة ({$req->ref}) من {$req->sent_by} بانتظار موافقتكم — {$req->day} · {$req->time}.");

        return back()->with('success', "أُرسلت الدعوة ({$req->ref}) إلى الإدارة العليا بانتظار موافقتها — لا تصل العميل {$client->name} إلا بعد الاعتماد.");
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

        // **لا تُعتمد دعوةٌ فات موعدها** (قرار المالك 2026-10-01). كانت تُقبل حتى تنقضي مهلة انتهائها
        // (`meet_invite_expire_minutes` بعد الموعد)، فيُبلَّغ العميل «اجتماع مجدول» بموعدٍ مضى. تُوسَم
        // منتهيةً (والوسم يبقى، فالحفظ قبل الرمي بلا معاملة) — فيظهر زرّ «إعادة الإرسال» بموعدٍ جديد بدل الانتظار حتى يمرّ المجدول.
        $startsAt = MeetingTime::parse($meetRequest->day, $meetRequest->time);
        if ($startsAt !== null && $startsAt->isPast()) {
            $meetRequest->update(['stage' => MeetRequest::STAGE_EXPIRED]);

            // خطأُ تحقّقٍ لا تحويلٌ بـflash: الواجهة تعلن النجاح على كلّ تحويل (`onSuccess`)
            throw ValidationException::withMessages(['approve' => "فات موعد الدعوة {$meetRequest->ref} قبل اعتمادها — صارت منتهية الصلاحيّة، أعد إرسالها بموعدٍ جديد."]);
        }

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
            'lawyer_id' => ['required_without:meeting_id', 'nullable', 'integer', 'exists:users,id'],
            // إعادة جدولة اجتماع: انشغال مسؤوله **ومشاركيه** معاً، والاجتماع لا يحجب نفسه —
            // الحكم نفسه الذي يرفض به `MeetingController::reschedule`
            'meeting_id' => ['nullable', 'integer', 'exists:meetings,id'],
            'day' => ['required', 'date_format:Y-m-d'],
        ]);

        $fmt = fn (int $min) => sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
        $intervals = ! empty($data['meeting_id'])
            ? $this->meetingBusy($request, Meeting::findOrFail($data['meeting_id']), $data['day'])
            : $this->busy((int) $data['lawyer_id'], $data['day']);

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
     * LawyerAvailability::busyIntervals ويقرأ الخمسة (ومنها جلسات المحاكم).
     *
     * @return array<int, array{0:int,1:int,2:BusyKind}>
     */
    private function busy(int $lawyerId, string $day): array
    {
        return LawyerAvailability::busyIntervals($lawyerId, $day);
    }

    /**
     * فترات انشغال أهل الاجتماع (المسؤول والمشاركون) مجتمعةً، بلا الاجتماع نفسه ودعوته.
     *
     * @return array<int, array{0:int,1:int,2:BusyKind}>
     */
    private function meetingBusy(Request $request, Meeting $meeting, string $day): array
    {
        // المحامي يرى انشغال اجتماعٍ هو مسؤوله أو مشاركٌ فيه وحده — كحارس الاطّلاع على الاجتماع
        abort_if($request->user()->role === Role::Lawyer && ! $meeting->involves($request->user()), 403);

        return array_merge(...array_values(LawyerAvailability::busyIntervalsForMany($meeting->staffIds(), $day, $meeting->id)));
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
        if ($meetRequest->assigned_lawyer_id && LawyerAvailability::isBusy($meetRequest->assigned_lawyer_id, $startsAt)) {
            throw ValidationException::withMessages(['time' => self::TAKEN]);
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

        // **رسالةُ النجاح كانت تُرسَل ولو لم يقع إلغاء.** كان الشرط يلفّ الفعل وحده
        // ثمّ يعود `back()` في الحالين، فينقر المستخدم «إلغاء» على دعوةٍ انعقدت
        // فيقرأ «تم إلغاء الدعوة» وهي كما كانت. الحارس صريحٌ الآن ورسالتُه تصل.
        abort_if(
            $meetRequest->stage >= MeetRequest::STAGE_EXECUTED,
            422,
            'انعقدت هذه الجلسة أو تجاوزت مرحلة الإلغاء — لا تُلغى بعد انعقادها.'
        );

        // **إلغاء الدعوة يُلغي اجتماعها ويحذف غرفته** (قرار المالك 2026-09-26). كان يُنزّل الدعوة
        // وحدها، فيبقى اجتماعها «قادماً» في اجتماعات العميل وغرفته حيّة على Zoom — يحضر العميل
        // لاجتماعٍ أُلغيت دعوته. بانتقال الإلغاء نفسه الذي يسلكه زرّ الاجتماع (`CancelMeeting`) —
        // وحارسُه يرفض الجاري برسالته، فلا تُلغى دعوةٌ والطرفان في غرفتها.
        $meeting = $meetRequest->meeting;
        if ($meeting !== null && ! MeetingStatus::isFinalValue($meeting->status)) {
            Workflow::run(new CancelMeeting, $meeting, $request->user(), ['via' => MeetingCancelled::VIA_INVITATION]);
        }

        $meetRequest->update(['stage' => MeetRequest::STAGE_CANCELLED]);
        Notify::send($meetRequest->user_id, 'info', 't-grey', "أُلغيت دعوة الاجتماع ({$meetRequest->ref}) — «{$meetRequest->service}».");

        return back()->with('flash', $meeting !== null ? 'أُلغيت الدعوة واجتماعها، وحُذفت غرفته على Zoom.' : 'أُلغيت الدعوة.');
    }

    // بدء تنفيذ الجلسة (مرحلة 2) — يفتح المكتب Zoom ويُعلَّم الاجتماع «جارٍ»
    public function start(Request $request, MeetRequest $meetRequest): RedirectResponse
    {
        $this->guardOwner($request, $meetRequest);
        if ($meetRequest->stage === MeetRequest::STAGE_CONFIRMED) {
            // «جارٍ» بانتقال البدء نفسه الذي يسلكه زرّ الاجتماع وويبهوك Zoom (`StartMeeting`) — كان
            // يُكتب هنا بلا شرطٍ على حالة الاجتماع فيُحيي «ملغى» أو «منتهٍ». والانتقال يرفع الدعوة
            // إلى «نُفّذت» ويبثّ؛ وما بدأ قبلُ (Zoom أو زميل) لا يُعاد بدؤه.
            $meeting = $meetRequest->meeting;
            if ($meeting !== null && $meeting->status !== MeetingStatus::Live->value) {
                Workflow::run(new StartMeeting, $meeting, $request->user(), ['source' => SessionEndedInSystem::VIA_STAFF]);
            }
            $meetRequest->update(['stage' => MeetRequest::STAGE_EXECUTED]);
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
