<?php

namespace App\Support;

use App\Enums\Role;
use App\Events\TicketMessageBroadcast;
use App\Mail\CaseConvertedMail;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Services\LegalAiService;
use App\Services\MailService;
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
        $analysis = app(LegalAiService::class)->classifyCase($ticket);

        $case = DB::transaction(function () use ($ticket, $analysis) {
            $number = 'CASE-'.now()->year.'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

            $case = LegalCase::create([
                'user_id' => $ticket->user_id,
                'ticket_id' => $ticket->id,
                'number' => $number,
                'type' => $analysis['type'],
                'assigned_lawyer' => $ticket->assigned_lawyer,
                'assigned_lawyer_id' => $ticket->assigned_lawyer_id,
                'department' => $analysis['department'],
                'status' => 'بانتظار اعتماد الأتعاب',
                'tone' => CaseJourney::toneFor('بانتظار اعتماد الأتعاب'),
                'update_text' => 'تم تحويل الاستشارة إلى قضية، بانتظار تحديد الإدارة للأتعاب',
                'fee_status' => 'none',
            ]);

            $details = [];
            if ($ticket->opponent_name) {
                $details[] = "الخصم: {$ticket->opponent_name}";
            }
            if ($ticket->court_name) {
                $details[] = "المحكمة المختصة: {$ticket->court_name}";
            }
            if ($ticket->claim_amount) {
                $details[] = 'المبلغ المطالب به: '.number_format((float) $ticket->claim_amount).' ر.س';
            }
            $extraChips = ! empty($details) ? implode('', array_map(fn ($d) => '<span class="doc-chip">'.e($d).'</span>', $details)) : '';

            $case->messages()->create([
                'who' => 'ai',
                'name' => 'المساعد القانوني',
                'role' => 'تحليل',
                'body' => '<p>تحليل ذكي للطلب:</p><div class="doc-list"><span class="doc-chip">نوع القضية: '.e($analysis['type']).'</span><span class="doc-chip">القسم المختص: '.e($analysis['department']).'</span>'.$extraChips.'</div>',
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
        Live::push(new TicketMessageBroadcast($msg));

        Notify::send($ticket->user_id, 'scale', 't-cyan', "تم تحويل تذكرتك {$ticket->number} إلى قضية قانونية رقم {$case->number}. تابعها من «القضايا».");

        // بريد للعميل بتحويل التذكرة إلى قضية (أفضل-جهد — لا يعطّل التحويل إن فشل)
        $ticket->loadMissing('user');
        if ($ticket->user?->email) {
            $case->setRelation('user', $ticket->user);
            app(MailService::class)->send($ticket->user, new CaseConvertedMail($case, $ticket->number));
        }

        return $case;
    }

    private static function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
