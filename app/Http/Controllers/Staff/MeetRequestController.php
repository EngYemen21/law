<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Role;
use App\Events\MeetingStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Models\MeetRequest;
use App\Models\User;
use App\Support\ClientDirectory;
use App\Support\Live;
use App\Support\Notify;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $reqs = MeetRequest::with('user')->latest('id')->get()
            ->map(fn (MeetRequest $r) => $r->toCard());

        return Inertia::render($this->prefix($request).'/meetreqs', [
            'requests' => $reqs,
            'clients' => ClientDirectory::list(),
        ]);
    }

    // إرسال دعوة اجتماع لعميل مسجّل (يطابق sendMeetInvite) — تصل لإشعاراته ودعواته
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'service' => ['nullable', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:استشارة مرئية,استشارة حضورية,استشارة هاتفية'],
            'case_ref' => ['nullable', 'string', 'max:120'],
            'day' => ['nullable', 'string', 'max:40'],
            'time' => ['nullable', 'string', 'max:20'],
        ]);

        $client = User::findOrFail($data['client_id']);
        abort_unless($client->role === Role::Client, 422);

        $req = MeetRequest::create([
            'user_id' => $client->id,
            'ref' => 'MR-'.random_int(1000, 9999),
            'service' => trim($data['service'] ?? '') ?: 'استشارة',
            'type' => $data['type'],
            'case_ref' => ($data['case_ref'] ?? '') ?: null,
            'day' => trim($data['day'] ?? '') ?: '—',
            'time' => trim($data['time'] ?? '') ?: '—',
            'sent_by' => $request->user()->name.' ('.$request->user()->role->label().')',
        ]);

        Notify::send($client->id, 'video', 't-blue', "دعوة اجتماع جديدة ({$req->ref}): {$req->type} بشأن «{$req->service}» — {$req->day} · {$req->time}. أكّد حضورك من «دعوات الاجتماعات».");

        return back();
    }

    // إلغاء دعوة لم تؤكَّد بعد (يطابق mrCancel)
    public function cancel(Request $request, MeetRequest $meetRequest): RedirectResponse
    {
        if ($meetRequest->stage === MeetRequest::STAGE_SENT) {
            $meetRequest->delete();
        }

        return back();
    }

    // بدء تنفيذ الجلسة (مرحلة 2) — يفتح المكتب Zoom ويُعلَّم الاجتماع «جارٍ»
    public function start(Request $request, MeetRequest $meetRequest): RedirectResponse
    {
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

    private function prefix(Request $request): string
    {
        return match ($request->user()->role) {
            Role::Employee => 'employee',
            Role::Lawyer => 'lawyer',
            default => 'admin',
        };
    }
}
