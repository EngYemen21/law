<?php

namespace App\Http\Controllers\Admin;

use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\LegalAiService;
use App\Support\Live;
use App\Support\Notify;
use App\Support\TicketJourney;
use App\Support\TicketResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إشراف الإدارة العليا — رؤية كاملة لكل التذاكر وملخصاتها ومسار معالجتها.
 */
class TicketController extends Controller
{
    public function index(): Response
    {
        // الإدارة العليا ترى أسماء العملاء كاملةً (المرجع صريح)؛ التشفير يبقى بين العميل والمحامي/الموظف
        $tickets = Ticket::with(['user', 'summary'])->latest('id')->get()
            ->map(fn (Ticket $t) => array_merge($t->toEmployeeCard(), ['client' => $t->user?->name ?? '—']));

        return Inertia::render('admin/tickets', ['tickets' => $tickets]);
    }

    public function show(Ticket $ticket): Response
    {
        $ticket->load(['user', 'summary', 'legalCase']);

        return Inertia::render('lawyer/ticketchat', [
            'ticket' => array_merge($ticket->toEmployeeCard(), [
                'caseRef' => $ticket->legalCase?->number,
                // الإدارة ترى الاسم الكامل + الجوال (بطاقة «تفاصيل الطلب»)
                'client' => $ticket->user?->name ?? '—',
                'mobile' => $ticket->user?->phone,
                'openedAt' => $ticket->created_at?->locale('ar')->translatedFormat('j F Y'),
            ]),
            'channel' => 'ticket.'.$ticket->id,
            'messages' => $ticket->messages->map->toMessage(),
            'summary' => $ticket->summary?->toData(),
            // كانت مفقودة ⇒ canConvert صحيح دائماً فيظهر زر التحويل حتى بعد التحويل
            'converted' => (bool) $ticket->legalCase,
            'base' => '/admin',
        ]);
    }

    public function summaries(): Response
    {
        $summaries = Ticket::with(['summary', 'user'])->whereHas('summary')->latest('id')->get()
            ->map(fn (Ticket $t) => array_merge($t->summary->toData(), [
                'type' => $t->type,
                // الإدارة ترى الاسم الكامل
                'client' => $t->user?->name ?? '—',
            ]));

        return Inertia::render('admin/summaries', ['summaries' => $summaries]);
    }

    // الاعتماد النهائي للإدارة → بطاقة النتيجة تصل العميل وتكتمل التذكرة (يطابق tfAdminReview→tfResult)
    public function approveResult(Request $request, Ticket $ticket): RedirectResponse
    {
        $summary = $ticket->summary;
        abort_unless($summary && $summary->result_status === 'pending_admin', 404);

        $summary->update(['result_status' => 'approved']);

        $ack = $ticket->messages()->create([
            'who' => 'admin',
            'name' => 'الإدارة',
            'role' => 'اعتماد',
            'body' => '<p>تم اعتماد ملخص الاستشارة ومحضر الجلسة من الإدارة. تُرسل النتيجة النهائية الآن.</p>',
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($ack));

        $result = $ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => 'النتيجة',
            'body' => TicketResult::card($ticket, $summary),
            'time_label' => $this->clock(),
        ]);
        Live::push(new TicketMessageBroadcast($result));

        $ticket->update([
            'status' => 'مكتملة',
            'tone' => TicketJourney::toneFor('مكتملة'),
            'last_message' => 'اكتملت معالجة الطلب، والنتيجة النهائية متاحة في التذكرة',
            'date_label' => 'الآن',
        ]);
        Live::push(new TicketStatusBroadcast($ticket));

        Notify::send($ticket->user_id, 'check', 't-green', "اكتملت معالجة تذكرتك {$ticket->number}، والنتيجة النهائية والتوصيات متاحة داخل التذكرة.");

        return redirect()->route('admin.tickets');
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
