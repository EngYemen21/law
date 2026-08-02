<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Support\TicketAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * إسناد تذكرة لمحامٍ في الخلفية — يُبعد نداء AI (chooseLawyer) عن طلب الإدارة.
 * يختار المحامي بالـAI خارج القفل، ثم يكتب الإسناد في معاملة قصيرة مقفولة لصفٍّ واحد
 * (لا يُقفل الدفعة كلها ولا يُحتجز القفل أثناء نداء الـAI).
 */
class AssignTicketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CLOSED = ['مكتملة', 'مغلقة'];

    public function __construct(
        public int $ticketId,
        public string $actorName
    ) {}

    public function handle(): void
    {
        $ticket = Ticket::find($this->ticketId);
        if ($ticket === null || $ticket->assigned_lawyer_id || in_array($ticket->status, self::CLOSED, true)) {
            return;
        }

        // اختيار المحامي (يتضمّن نداء AI) خارج أي قفل
        $lawyer = TicketAssignment::pickLawyer($ticket);
        if ($lawyer === null) {
            return;
        }

        // كتابة الإسناد في معاملة قصيرة مع قفل صفٍّ واحد + إعادة فحص السباق
        DB::transaction(function () use ($lawyer): void {
            $locked = Ticket::whereKey($this->ticketId)->lockForUpdate()->first();
            if ($locked === null || $locked->assigned_lawyer_id || in_array($locked->status, self::CLOSED, true)) {
                return;
            }

            $updates = [
                'assigned_lawyer' => $lawyer->name,
                'assigned_lawyer_id' => $lawyer->id,
                'branch' => $lawyer->branch ?: $locked->branch,
            ];

            if (in_array($locked->status, ['جديدة', 'قيد التحليل'], true)) {
                $updates['status'] = 'محالة للقسم القانوني';
                $updates['tone'] = \App\Support\TicketJourney::toneFor('محالة للقسم القانوني');
                $updates['last_message'] = 'تمت إحالة طلبكم إلى القسم القانوني المختص لدراسة الموضوع.';
                $updates['date_label'] = 'الآن';
            }

            $locked->update($updates);
            TicketAssignment::syncRelatedConsults($locked->fresh());
            \App\Support\Live::push(new \App\Events\TicketStatusBroadcast($locked));
            $locked->messages()->create([
                'who' => 'note',
                'name' => $this->actorName,
                'role' => 'توزيع آلي',
                'body' => '<p>توزيع تلقائي إلى '.e($lawyer->name).'.</p>',
                'time_label' => 'الآن',
            ]);
        });
    }
}
