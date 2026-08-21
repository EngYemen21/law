<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Jobs\AssignTicketJob;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Support\Live;
use App\Support\TicketAssignment;
use App\Support\TicketJourney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * توزيع التذاكر — الإدارة تُسند التذاكر النشطة إلى المحامين (يكتب assigned_lawyer_id فعلياً).
 * توفر إسناداً يدوياً (assign) وتلقائياً (auto) عبر محرك TicketAssignment (تخصّص + حمل + أقدمية + AI).
 */
class DistributeController extends Controller
{
    private const CLOSED = ['مكتملة', 'مغلقة'];

    public function index(): Response
    {
        $tickets = Ticket::with('user')->whereNotIn('status', self::CLOSED)->latest('id')->get()
            ->map(fn (Ticket $t) => array_merge($t->toEmployeeCard(), ['no' => $t->number]));

        return Inertia::render('admin/distribute', [
            'tickets' => $tickets,
            'lawyers' => User::where('role', Role::Lawyer)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            // الإدارة تُسند لمحامٍ نشط — Rule موحَّد يرفض غير المحامين والموقوفين.
            'lawyer_id' => ['required', 'integer', new ActiveLawyer],
        ]);
        $lawyer = User::findOrFail($data['lawyer_id']);

        $updates = [
            'assigned_lawyer' => $lawyer->name,
            'assigned_lawyer_id' => $lawyer->id,
        ];

        if (in_array($ticket->status, ['جديدة', 'قيد التحليل'], true)) {
            $updates['status'] = 'محالة للقسم القانوني';
            $updates['tone'] = TicketJourney::toneFor('محالة للقسم القانوني');
            $updates['last_message'] = 'تمت إحالة طلبكم إلى القسم القانوني المختص لدراسة الموضوع.';
            $updates['date_label'] = 'الآن';
        }

        $ticket->update($updates);
        // انتشار المحامي الجديد إلى استشارات التذكرة المفتوحة
        TicketAssignment::syncRelatedConsults($ticket->fresh());
        Live::push(new TicketStatusBroadcast($ticket));

        $ticket->messages()->create([
            'who' => 'note', 'name' => $request->user()->name, 'role' => 'توزيع',
            'body' => '<p>أسندت الإدارة التذكرة إلى '.e($lawyer->name).'.</p>', 'time_label' => 'الآن',
        ]);

        return back()->with('flash', "تم إسناد التذكرة {$ticket->number} إلى {$lawyer->name}.");
    }

    /**
     * التوزيع التلقائي: يُرسل مهمّة إسناد لكل تذكرة نشطة غير مُسندة (assigned_lawyer_id = null).
     * نداء الـAI (chooseLawyer) يجري في الخلفية داخل AssignTicketJob — فلا يُعلَّق طلب الإدارة
     * ولا تُقفل دفعة التذاكر أثناء نداءات الـAI. كل مهمّة تقفل صفّها وتعيد فحص السباق قبل الكتابة.
     */
    public function auto(Request $request): RedirectResponse
    {
        $actorName = $request->user()->name;
        $tickets = Ticket::whereNotIn('status', self::CLOSED)
            ->whereNull('assigned_lawyer_id')
            ->get(['id']);

        foreach ($tickets as $ticket) {
            AssignTicketJob::dispatch($ticket->id, $actorName);
        }

        $n = $tickets->count();
        $msg = $n > 0
            ? "جارٍ توزيع {$n} تذكرة تلقائياً في الخلفية — حدّث الصفحة بعد قليل لرؤية الإسناد."
            : 'لا توجد تذاكر غير مُسندة للتوزيع.';

        return back()->with('flash', $msg);
    }
}
