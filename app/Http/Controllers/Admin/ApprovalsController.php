<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Transitions\Consult\RejectProposedAppointment;
use App\Domain\Journey\Transitions\Ticket\RejectOutcomeTrack;
use App\Domain\Journey\Transitions\Ticket\RejectTicketResult;
use App\Domain\Journey\Transitions\Ticket\ReturnTicketSummary;
use App\Domain\Journey\Workflow;
use App\Http\Controllers\Controller;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\TicketSummary;
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
        $ticketSummaries = TicketSummary::with(['ticket.user', 'ticket.assignedLawyer', 'lawyer'])
            ->where('status', 'awaiting_admin')
            ->whereHas('ticket')
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
        $ticketTrackProposals = Ticket::with(['user', 'proposedBy', 'assignedLawyer'])
            ->whereNotNull('proposed_track')
            ->whereNull('approved_track')
            ->where('is_frozen', false)
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
                'since' => ($t->proposed_at ?? $t->updated_at)?->locale('ar')->diffForHumans(),
                'at' => ($t->proposed_at ?? $t->updated_at)?->toIso8601String(),
            ])->values();

        // 3. محاضر الجلسات ونتائج الاستشارات — الأحدث اعتماداً في أول الجدول
        $sessionSummaries = Consult::with(['user', 'ticket:id,number'])
            ->whereNotNull('summary_lawyer_approved_at')
            ->whereNull('summary_approved_at')
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
        $appointments = Consult::with(['user', 'appointment'])
            ->where('status', 'بانتظار اعتماد الموعد')
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
            $consult = Consult::where('ref', $data['ref'])->firstOrFail();
            $consult->update([
                'summary_lawyer_approved_at' => null,
            ]);
            $consult->logAudit($admin->name, 'رفض محضر الجلسة', '—', $reason);
            $consult->save();

            return back()->with('flash', 'تمت إعادة محضر الجلسة للمستشار لاستكماله.');
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

    /**
     * استبعاد أو حذف البند من قائمة الانتظار / الأرشيف.
     */
    public function dismiss(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(['track', 'summary', 'session', 'appointment', 'history'])],
            'ref' => ['required', 'string'],
        ]);

        $admin = $request->user();

        if ($data['type'] === 'track') {
            // كان تحديثاً جماعيّاً يمسح المقترح ويترك الحالة «بانتظار اعتماد الإدارة للمسار» —
            // فتعلق التذكرة بانتظار مقترحٍ لم يعد قائماً. الاستبعاد رفضٌ بلا سببٍ ولا إشعار.
            $ticket = Ticket::where('number', $data['ref'])->firstOrFail();
            Workflow::run(new RejectOutcomeTrack, $ticket, $admin, ['dismiss' => true]);

            return back()->with('flash', 'تم استبعاد المقترح بنجاح.');
        }

        if ($data['type'] === 'summary') {
            $ticket = Ticket::where('number', $data['ref'])->first();
            if ($ticket?->summary) {
                Workflow::run(new ReturnTicketSummary, $ticket->summary, $admin);
            }

            return back()->with('flash', 'تم استبعاد ملخص التذكرة وإعادته لمرحلة المستشار.');
        }

        if ($data['type'] === 'session') {
            $consult = Consult::where('ref', $data['ref'])->first();
            $consult?->update([
                'summary_lawyer_approved_at' => null,
            ]);

            return back()->with('flash', 'تم استبعاد محضر الجلسة من قائمة المتابعة.');
        }

        if ($data['type'] === 'appointment') {
            $consult = Consult::where('ref', $data['ref'])->first();
            if ($consult !== null) {
                Workflow::run(new RejectProposedAppointment, $consult, $admin);
            }

            return back()->with('flash', 'تم حذف حجز الموعد بنجاح.');
        }

        return back()->with('flash', 'تم استبعاد البند بنجاح.');
    }
}
