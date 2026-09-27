<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Journey\Enums\ClosureReasonCode;
use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Transitions\Consult\RejectProposedAppointment;
use App\Domain\Journey\Transitions\Consult\ReturnConsultSummary;
use App\Domain\Journey\Transitions\Ticket\OutcomeSummaryGate;
use App\Domain\Journey\Transitions\Ticket\RejectOutcomeTrack;
use App\Domain\Journey\Transitions\Ticket\RejectTicketResult;
use App\Domain\Journey\Transitions\Ticket\ReturnTicketSummary;
use App\Domain\Journey\Workflow;
use App\Http\Controllers\Controller;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Support\AdminApprovalQueue;
use App\Support\Notify;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * **«مركز الاعتمادات والقرارات» — لوحة القيادة العليا للمصادقة والتوجيه** (قرار المالك 2026-09-14).
 *
 * يشمل إدارة ومصادقة:
 *   - مقترحات مسار مآل التذاكر (استشارة / قضية / تنفيذ / إغلاق) مع حيثيات الذكاء الاصطناعي والتسبيب الحقيقي.
 *   - ملخّصات التذاكر التي اعتمدها المستشار (الرأي القانونيّ المبدئيّ).
 *   - ملخّصات ونتائج الجلسات التي اعتمدها المستشار.
 *   - مواعيد الجلسات التي حجزها موظّف.
 *   - السجل التاريخي للملخصات والنتائج ومسار المراجعة.
 */
class ApprovalsController extends Controller
{
    public function index(): Response
    {
        // 1. ملخصات التذاكر المعلقة — الأحدث اعتماداً وتحديثاً من المستشار في أول الجدول
        // القوائم الأربع من تعريفها الواحد (`AdminApprovalQueue`) — يقرؤه رادار اللوحة وتبويب التذاكر أيضاً
        $ticketSummaries = AdminApprovalQueue::ticketSummaries()->with(['ticket.user', 'ticket.assignedLawyer', 'lawyer'])
            ->orderByRaw('COALESCE(lawyer_approved_at, updated_at) DESC')
            ->get()
            ->map(function (TicketSummary $s) {
                $t = $s->ticket;

                return [
                    'id' => $t->id,
                    'summaryId' => $s->id,
                    'no' => $t->number,
                    'type' => $t->type,
                    'department' => $t->department ?: 'عام',
                    'priority' => $t->priority ?: 'متوسطة',
                    'client' => $t->user?->name ?? '—',
                    'clientPhone' => $t->user?->phone ?? '—',
                    'lawyer' => $s->lawyer?->name ?? ($t->assigned_lawyer ?: '—'),
                    'facts' => $s->facts,
                    'keyPoints' => $s->key_points,
                    'caseSummary' => $s->case_summary,
                    'since' => ($s->lawyer_approved_at ?? $s->updated_at)?->locale('ar')->diffForHumans(),
                    'at' => ($s->lawyer_approved_at ?? $s->updated_at)?->toIso8601String(),
                ];
            })->values();

        // 2. مقترحات مسار مآل التذاكر — الأحدث اقتراحاً في أول الجدول
        $ticketTrackProposals = AdminApprovalQueue::trackProposals()->with(['user', 'proposedBy', 'assignedLawyer', 'summary'])
            ->orderByRaw('COALESCE(proposed_at, updated_at) DESC')->get()
            ->map(fn (Ticket $t) => [
                'id' => $t->id,
                'no' => $t->number,
                'type' => $t->type,
                'department' => $t->department ?: 'عام',
                'priority' => $t->priority ?: 'متوسطة',
                'client' => $t->user?->name ?? '—',
                'clientPhone' => $t->user?->phone ?? '—',
                'proposedTrack' => $t->proposed_track,
                'proposedTrackLabel' => TicketOutcomeTrack::tryFrom((string) $t->proposed_track)?->label() ?? $t->proposed_track,
                'proposedTrackReason' => $t->proposed_track_reason,
                'proposedBy' => $t->proposedBy?->name ?? '—',
                'proposedByRole' => $t->proposedBy?->role?->label() ?? 'مسؤول',
                'aiSuggestedTrack' => $t->ai_suggested_track,
                'aiSuggestedTrackLabel' => TicketOutcomeTrack::tryFrom((string) $t->ai_suggested_track)?->label() ?? $t->ai_suggested_track,
                'aiSuggestedReason' => $t->ai_suggested_reason,
                /*
                 * **حارس المآل نفسه يصل الصفّ** (`OutcomeSummaryGate`، ث٥): كان الاعتماد من هنا يُرسل
                 * المسار والتسبيب وحدهما، فكلّ مقترحٍ بلا ملخّصٍ معتمد يُردّ 422 وتقول الشاشة «حدث خطأ».
                 * الآن الصفّ يحمل المانع وسبب التجاوز الموروث، فتطلب النافذة سبب التجاوز حيث يلزم وحده.
                 */
                'outcomeBlocker' => OutcomeSummaryGate::blocker($t),
                'consultBlocker' => OutcomeSummaryGate::consultBlocker($t),
                'inheritedWaiver' => OutcomeSummaryGate::inheritedWaiver($t, auth()->user()),
                'since' => ($t->proposed_at ?? $t->updated_at)?->locale('ar')->diffForHumans(),
                'at' => ($t->proposed_at ?? $t->updated_at)?->toIso8601String(),
            ])->values();

        // 3. محاضر الجلسات ونتائج الاستشارات — الأحدث اعتماداً في أول الجدول
        $sessionSummaries = AdminApprovalQueue::sessionSummaries()->with(['user', 'ticket:id,number'])
            ->orderByRaw('COALESCE(summary_lawyer_approved_at, updated_at) DESC')->get()
            ->map(fn (Consult $c) => [
                'id' => $c->id,
                'ref' => $c->ref,
                'ticketNo' => $c->ticket?->number,
                'client' => $c->user?->name ?? '—',
                'clientPhone' => $c->user?->phone ?? '—',
                'lawyer' => $c->lawyer ?: '—',
                'channel' => $c->channel ?: 'مرئية',
                'summary' => $c->summary,
                'since' => ($c->summary_lawyer_approved_at ?? $c->updated_at)?->locale('ar')->diffForHumans(),
                'at' => ($c->summary_lawyer_approved_at ?? $c->updated_at)?->toIso8601String(),
            ])->values();

        // 4. مواعيد الاستشارات المحجوزة — الأحدث حجزاً وتعديلاً في أول الجدول
        $appointments = AdminApprovalQueue::appointments()->with(['user', 'appointment'])
            ->latest('updated_at')->get()
            ->map(fn (Consult $c) => [
                'id' => $c->id,
                'ref' => $c->ref,
                'client' => $c->user?->name ?? '—',
                'clientPhone' => $c->user?->phone ?? '—',
                'day' => $c->appointment?->day,
                'time' => $c->appointment?->time,
                'lawyer' => $c->appointment?->lawyer ?? '—',
                'channel' => str_replace('استشارة ', '', (string) $c->appointment?->type),
                'since' => $c->updated_at?->locale('ar')->diffForHumans(),
            ])->values();

        // 5. السجل التاريخي لملخصات التذاكر والنتائج — الأحدث اعتماداً ومصادقةً يعرض في أول الجدول فورياً
        $approvedHistory = TicketSummary::with(['ticket.user', 'lawyer'])
            ->whereHas('ticket')
            ->orderByRaw('COALESCE(approved_at, lawyer_approved_at, updated_at) DESC')
            ->limit(100)
            ->get()
            ->map(function (TicketSummary $s) {
                $t = $s->ticket;
                $date = $s->approved_at ?? $s->lawyer_approved_at ?? $s->updated_at ?? $t?->created_at;

                return array_merge($s->toData(), [
                    'type' => $t?->type ?? 'استشارة / قضية',
                    'client' => $t?->user?->name ?? '—',
                    'clientPhone' => $t?->user?->phone ?? '—',
                    'department' => $t?->department ?: 'عام',
                    'createdAtFormatted' => $date?->locale('ar')->translatedFormat('d M Y - h:i a') ?? 'مؤخراً',
                ]);
            })->values();

        $counts = [
            'proposals' => $ticketTrackProposals->count(),
            'summaries' => $ticketSummaries->count(),
            'sessions' => $sessionSummaries->count(),
            'appointments' => $appointments->count(),
            'history' => $approvedHistory->count(),
            'totalPending' => $ticketTrackProposals->count() + $ticketSummaries->count() + $sessionSummaries->count() + $appointments->count(),
        ];

        return Inertia::render('admin/approvals', [
            'ticketSummaries' => $ticketSummaries,
            'ticketTrackProposals' => $ticketTrackProposals,
            'sessionSummaries' => $sessionSummaries,
            'appointments' => $appointments,
            'approvedHistory' => $approvedHistory,
            'counts' => $counts,
            // أسباب الإغلاق من الكتالوج — يختار المدير أحدها حين يعتمد مسار «إغلاق» (كان يُكتب `NoLegalMerit` صامتاً)
            'closureReasons' => ClosureReasonCode::options(),
        ]);
    }

    /**
     * رفض الطلب أو إعادته للمستشار/الموظف للتعديل مع تدوين الملاحظات.
     */
    public function reject(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(['track', 'summary', 'session', 'appointment', 'history'])],
            'ref' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $admin = $request->user();
        $reason = trim($data['reason']);

        if ($data['type'] === 'track') {
            $ticket = Ticket::where('number', $data['ref'])->firstOrFail();
            $proposerId = $ticket->proposed_by_id;
            Workflow::run(new RejectOutcomeTrack, $ticket, $admin, ['reason' => $reason]);
            $ticket->messages()->create([
                'who' => 'note',
                'name' => $admin->name,
                'role' => 'إدارة',
                'body' => '<p><b>رفض مقترح المسار من الإدارة العليا:</b> '.e($reason).'</p>',
                'time_label' => now()->format('h:i A'),
            ]);
            if ($proposerId) {
                Notify::send($proposerId, 'user', 't-amber', "تم رفض مقترح المسار للتذكرة {$ticket->number}: {$reason}");
            }

            return back()->with('flash', 'تم رفض مقترح المسار وإعادته للمستشار بنجاح.');
        }

        if ($data['type'] === 'summary') {
            $ticket = Ticket::where('number', $data['ref'])->firstOrFail();
            $summary = $ticket->summary;
            if ($summary) {
                $lawyerId = $summary->lawyer_id ?? $ticket->assigned_lawyer_id;
                Workflow::run(new ReturnTicketSummary, $summary, $admin, ['reason' => $reason, 'return_ticket' => true]);
                $ticket->messages()->create([
                    'who' => 'note',
                    'name' => $admin->name,
                    'role' => 'إدارة',
                    'body' => '<p><b>إعادة ملخص الملف للمستشار للمراجعة:</b> '.e($reason).'</p>',
                    'time_label' => now()->format('h:i A'),
                ]);
                if ($lawyerId) {
                    Notify::send($lawyerId, 'user', 't-amber', "أعادت الإدارة ملخص التذكرة {$ticket->number} للمراجعة: {$reason}");
                }
            }

            return back()->with('flash', 'تمت إعادة ملخص الملف للمستشار لاستكماله وفق ملاحظاتك.');
        }

        if ($data['type'] === 'session') {
            // الإعادة انتقالٌ بحارسٍ وقيد (`ReturnConsultSummary`) لا مسحُ عمود — والمستشار يُبلَّغ بالسبب
            $consult = Consult::where('ref', $data['ref'])->firstOrFail();
            $lawyerId = $consult->summary_lawyer_approved_by ?? $consult->assigned_lawyer_id;
            Workflow::run(new ReturnConsultSummary, $consult, $admin, ['reason' => $reason]);

            $consult->ticket?->messages()->create([
                'who' => 'note',
                'name' => $admin->name,
                'role' => 'إدارة',
                'body' => '<p><b>إعادة محضر الجلسة '.e($consult->ref).' للمستشار:</b> '.e($reason).'</p>',
                'time_label' => now()->format('h:i A'),
            ]);
            if ($lawyerId) {
                Notify::send($lawyerId, 'user', 't-amber', "أعادت الإدارة محضر الجلسة {$consult->ref} للاستكمال: {$reason}");
            }

            return back()->with('flash', 'تمت إعادة محضر الجلسة للمستشار لاستكماله وفق ملاحظاتك.');
        }

        if ($data['type'] === 'appointment') {
            $consult = Consult::where('ref', $data['ref'])->firstOrFail();
            Workflow::run(new RejectProposedAppointment, $consult, $admin, ['reason' => $reason]);

            return back()->with('flash', 'تم رفض الموعد وإعادته لتحديد موعد بديل.');
        }

        if ($data['type'] === 'history') {
            $ticket = Ticket::where('number', $data['ref'])->firstOrFail();
            if ($ticket->summary) {
                Workflow::run(new RejectTicketResult, $ticket->summary, $admin, ['reason' => $reason]);
            }
            $ticket->messages()->create([
                'who' => 'note',
                'name' => $admin->name,
                'role' => 'إدارة',
                'body' => '<p><b>رفض/إعادة تدقيق النتيجة من الإدارة العليا:</b> '.e($reason).'</p>',
                'time_label' => now()->format('h:i A'),
            ]);

            return back()->with('flash', 'تم تسجيل رفض النتيجة وإرسال التوجيهات بنجاح.');
        }

        return back();
    }
}
