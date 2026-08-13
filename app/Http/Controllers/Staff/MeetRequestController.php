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
use App\Support\ClientDirectory;
use App\Support\Live;
use App\Support\Notify;
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
            'day' => ['required', 'date', 'after_or_equal:today'],
            'time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
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
                throw ValidationException::withMessages(['time' => 'هذا الموعد محجوز للمحامي — اختر وقتاً آخر.']);
            }
        }

        $req = MeetRequest::create([
            'user_id' => $client->id,
            'ref' => 'MR-'.random_int(1000, 9999),
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

        Notify::send($client->id, 'video', 't-blue', "دعوة اجتماع جديدة ({$req->ref}): {$req->type} بشأن «{$req->service}» — {$req->day} · {$req->time}. أكّد حضورك من «دعوات الاجتماعات».");
        // بريد دعوة للعميل (أفضل-جهد عبر الطابور)
        app(MailService::class)->send($client, new MeetInviteMail($req));

        return back();
    }

    /**
     * المواعيد المحجوزة لمحامٍ في يوم (لمنتقي المواعيد): فترات [بداية، نهاية] بصيغة HH:MM.
     * يجمع اجتماعات المحامي واستشاراته ودعواته المعلّقة في ذلك اليوم.
     */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lawyer_id' => ['required', 'integer', 'exists:users,id'],
            'day' => ['required', 'date'],
        ]);

        $fmt = fn (int $min) => sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
        $busy = array_map(
            fn ($iv) => [$fmt($iv[0]), $fmt($iv[1])],
            $this->busy((int) $data['lawyer_id'], $data['day']),
        );

        return response()->json(['busy' => $busy]);
    }

    /**
     * فترات انشغال المحامي في يوم (دقائق منذ منتصف الليل): اجتماعات + استشارات + دعوات معلّقة.
     *
     * @return array<int, array{0:int,1:int}>
     */
    private function busy(int $lawyerId, string $day): array
    {
        $toMin = function (?string $hm): ?int {
            if (! $hm || ! preg_match('/^(\d{1,2}):(\d{2})/', $hm, $m)) {
                return null;
            }

            return ((int) $m[1]) * 60 + (int) $m[2];
        };

        $out = [];

        foreach (Meeting::where('assigned_lawyer_id', $lawyerId)->where('status', '!=', 'ملغى')
            ->whereDate('starts_at', $day)->get(['starts_at', 'dur']) as $m) {
            if (($s = $toMin($m->starts_at?->format('H:i'))) !== null) {
                $d = (int) (preg_match('/\d+/', (string) $m->dur, $mm) ? $mm[0] : 60) ?: 60;
                $out[] = [$s, $s + $d];
            }
        }

        foreach (Consult::where('assigned_lawyer_id', $lawyerId)->where('status', '!=', 'ملغاة')
            ->whereDate('starts_at', $day)->get(['starts_at', 'duration_min']) as $c) {
            if (($s = $toMin($c->starts_at?->format('H:i'))) !== null) {
                $out[] = [$s, $s + ((int) ($c->duration_min ?: 60))];
            }
        }

        foreach (MeetRequest::where('assigned_lawyer_id', $lawyerId)->where('day', $day)
            ->where('stage', '<', MeetRequest::STAGE_CONFIRMED)->get(['time', 'duration_min']) as $r) {
            if (($s = $toMin($r->time)) !== null) {
                $out[] = [$s, $s + ((int) ($r->duration_min ?: 60))];
            }
        }

        return $out;
    }

    // إلغاء دعوة لم تؤكَّد بعد (يطابق mrCancel)
    public function cancel(Request $request, MeetRequest $meetRequest): RedirectResponse
    {
        $this->guardOwner($request, $meetRequest);
        if ($meetRequest->stage === MeetRequest::STAGE_SENT) {
            $meetRequest->delete();
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
                $meeting->update(['status' => 'جارٍ', 'is_up' => true]);
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
