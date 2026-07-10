<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\UserNotification;
use App\Services\ZoomService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * دعوات الاجتماعات لدى العميل (يطابق viewMeetreqs + confirmMeetInvite):
 * يستقبل دعوة المكتب ويؤكّد حضوره فتُنشأ جلسة Zoom واجتماع قادم.
 */
class MeetRequestController extends Controller
{
    public function __construct(private ZoomService $zoom) {}

    public function index(Request $request): Response
    {
        $reqs = MeetRequest::with('user')
            ->where('user_id', $request->user()->id)
            ->latest('id')->get()
            ->map(fn (MeetRequest $r) => $r->toClientCard());

        return Inertia::render('meetreqs', ['requests' => $reqs]);
    }

    // تأكيد حضور العميل → جلسة Zoom + اجتماع «قادم» في اجتماعاته
    public function confirm(Request $request, MeetRequest $meetRequest): RedirectResponse
    {
        abort_unless($meetRequest->user_id === $request->user()->id, 403);

        if ($meetRequest->stage === MeetRequest::STAGE_SENT) {
            $zoom = $this->zoom->createMeeting("{$meetRequest->type} — {$meetRequest->service} ({$meetRequest->ref})");

            $meeting = Meeting::create([
                'user_id' => $meetRequest->user_id,
                'ref' => 'M-'.now()->format('y').random_int(100, 999),
                'title' => "{$meetRequest->type} — {$meetRequest->service}",
                'type' => 'اجتماع مع عميل',
                'client_name' => $request->user()->name,
                'when_label' => $meetRequest->day.' · '.$meetRequest->time,
                'status' => 'قادم',
                'case_ref' => $meetRequest->case_ref,
                'meet_id' => $zoom['id'] ?? null,
                'meet_link' => $zoom['join_url'] ?? null,
                'host_link' => $zoom['start_url'] ?? null,
                'created_by' => $meetRequest->sent_by,
                'before_items' => ['مراجعة موضوع الدعوة: '.$meetRequest->service, 'قراءة المستندات ذات الصلة', 'تجهيز جدول الأعمال'],
                'during_items' => ['تسجيل الجلسة', 'تحويل الصوت إلى نص', 'استخراج القرارات'],
                'after_items' => ['إنشاء الملخص', 'إعداد المحضر', 'تحويل القرارات إلى مهام'],
                'is_up' => true,
                'has_link' => true,
            ]);

            $meetRequest->update([
                'stage' => MeetRequest::STAGE_CONFIRMED,
                'meeting_id' => $meeting->id,
                'meet_id' => $zoom['id'] ?? null,
                'meet_link' => $zoom['join_url'] ?? null,
                'host_link' => $zoom['start_url'] ?? null,
            ]);

            UserNotification::create([
                'user_id' => $meetRequest->user_id,
                'icon' => 'video',
                'tone' => 't-green',
                'body' => "تم تأكيد حضورك لاجتماع «{$meetRequest->service}» ({$meetRequest->day} · {$meetRequest->time}) — رابط الجلسة متاح في صفحة الاجتماعات.",
                'time_label' => 'الآن',
                'is_read' => false,
            ]);
        }

        return back();
    }
}
