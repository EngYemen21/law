<?php

namespace App\Support;

use App\Enums\Role;
use App\Events\TicketMessageBroadcast;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

/**
 * تحويل تذكرة استشارة مكتملة إلى قضية قانونية (يطابق cfConvert).
 * إجراء مشترك بين الموظف والمحامي.
 */
class CaseConversion
{
    /** هل يمكن تحويل هذه التذكرة الآن؟ */
    public static function isEligible(Ticket $ticket): bool
    {
        return $ticket->status === 'مكتملة' && ! $ticket->legalCase()->exists();
    }

    /** ينشئ القضية (مع تحليل ذكي للنوع/القسم) ويُعلم العميل. يُعيد القضية. */
    public static function convert(Ticket $ticket, User $actor): LegalCase
    {
        // تحليل ذكي يقترح نوع القضية والقسم المختص (يطابق cfAnalysis)
        $analysis = app(\App\Services\LegalAiService::class)->classifyCase($ticket);

        $case = DB::transaction(function () use ($ticket, $analysis) {
            $number = 'CASE-'.now()->year.'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

            $case = LegalCase::create([
                'user_id' => $ticket->user_id,
                'ticket_id' => $ticket->id,
                'number' => $number,
                'type' => $analysis['type'],
                'assigned_lawyer' => $ticket->assigned_lawyer,
                'department' => $analysis['department'],
                'status' => 'بانتظار اعتماد الأتعاب',
                'tone' => 'b-amber',
                'update_text' => 'تم تحويل الاستشارة إلى قضية، بانتظار تحديد الإدارة للأتعاب',
                'fee_status' => 'none',
            ]);

            $case->messages()->create([
                'who' => 'ai',
                'name' => 'المساعد القانوني',
                'role' => 'تحليل',
                'body' => "<p>تحليل ذكي للطلب:</p><div class=\"doc-list\"><span class=\"doc-chip\">نوع القضية: {$analysis['type']}</span><span class=\"doc-chip\">القسم المختص: {$analysis['department']}</span></div>",
                'time_label' => self::clock(),
            ]);
            $case->messages()->create([
                'who' => 'system',
                'name' => 'النظام',
                'role' => 'تحويل',
                'body' => "<p>تم تحويل طلب الاستشارة (التذكرة {$ticket->number}) إلى قضية قانونية. تتولّى الإدارة تحديد الأتعاب لتفعيل القضية.</p>",
                'time_label' => self::clock(),
            ]);

            return $case;
        });

        // رسالة داخل محادثة التذكرة يراها العميل + بثّ لحظي
        $who = $actor->role === Role::Lawyer ? 'lawyer' : 'staff';
        $msg = $ticket->messages()->create([
            'who' => $who,
            'name' => $actor->name,
            'role' => 'تحويل لقضية',
            'body' => "<p>تم تحويل طلبكم إلى قضية قانونية رقم <b>{$case->number}</b>. يمكنكم متابعتها من قسم «القضايا».</p>",
            'time_label' => self::clock(),
        ]);
        broadcast(new TicketMessageBroadcast($msg));

        UserNotification::create([
            'user_id' => $ticket->user_id,
            'icon' => 'scale',
            'tone' => 't-cyan',
            'body' => "تم تحويل تذكرتك {$ticket->number} إلى قضية قانونية رقم {$case->number}. تابعها من «القضايا».",
            'time_label' => 'الآن',
            'is_read' => false,
        ]);

        return $case;
    }

    private static function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
