<?php

namespace App\Support;

use App\Domain\Journey\Transitions\Ticket\ApproveTicketResult;
use App\Domain\Journey\Transitions\Ticket\ReadyForOutcome;
use App\Domain\Journey\Transitions\Ticket\SessionEnded;
use App\Domain\Journey\Workflow;
use App\Events\TicketMessageBroadcast;
use App\Models\Consult;
use App\Models\User;
use App\Services\LegalAiService;
use Illuminate\Support\Facades\DB;

/**
 * **نتيجة الجلسة تُنشر مرّةً واحدة، بالنصّ الذي في «استشاراتي»** (قرار المالك 2026-09-14).
 *
 * كان للجلسة ملخّصان: «نتيجة الجلسة» في محادثة التذكرة — مركّبةٌ من دراسة **ما قبل** الجلسة
 * وتُعتمد بمسارٍ مستقلّ — و«ملخّص الاستشارة» في «استشاراتي» من الجلسة نفسها. فيقرأ العميل
 * نصّين مختلفين بعنوانٍ واحد، والترتيب يغيّر محتوى البطاقة.
 *
 * الآن: ملخّص الجلسة واحد (`consults.summary`)، واعتماد الإدارة له هو نفسه اعتماد نتيجة
 * التذكرة — تُنشر بطاقةٌ في المحادثة تُبنى من الملخّص المعتمد لحظة النشر، وتكتمل التذكرة.
 */
final class ConsultSessionOutcome
{
    /** حالاتٌ تنتظر فيها التذكرة نتيجة جلستها. */
    private const AWAITING_OUTCOME = ['موعد مؤكد', 'بانتظار ملخّص الجلسة'];

    /** انعقدت الجلسة وانتهت ⇒ التذكرة «بانتظار ملخّص الجلسة» — بلا زرّ للموظّف. */
    public static function sessionEnded(Consult $consult): void
    {
        $ticket = $consult->ticket;
        $transition = new SessionEnded;
        // غير المقبول يُتجاوز بصمتٍ كما كان — لا يصير خطأً في زرّ الإنهاء ولا في webhook Zoom
        if ($ticket === null || ! $transition->accepts((string) $ticket->status)) {
            return;
        }

        Workflow::run($transition, $ticket);
    }

    /**
     * اعتمدت الإدارة ملخّص الجلسة ⇒ بطاقة النتيجة في المحادثة، والتذكرة «بانتظار قرار المآل».
     *
     * **كلّه أو لا شيء.** كانت الرسالتان واعتماد النتيجة تُحفظ ثمّ يُنادى المحرّك؛ فإن رفض
     * (تذكرةٌ حُوّلت قضيّةً، أو اعتمادان متزامنان) بقيت الكتابة ناقصة والتذكرة عالقة بلا زرّ يُخرجها.
     * فصارت الكتابة ونقل الحالة في معاملةٍ واحدة يُلغيها الرفض، وما يخرج من النظام (البثّ
     * والإشعار والتدقيق) بعد الحفظ فقط — `DB::afterCommit` يُسقطه إن أُلغيت المعاملة، ويشمل
     * ذلك معاملةَ من ينادي هذه الدالّة (`AiReviewOutcome::approveConsultSummary`).
     */
    public static function publish(Consult $consult, User $admin): void
    {
        $ticket = $consult->ticket;
        if ($ticket === null || ! in_array($ticket->status, self::AWAITING_OUTCOME, true)) {
            return;
        }

        $clock = now()->format('h:i').' '.(now()->hour < 12 ? 'ص' : 'م');

        DB::transaction(function () use ($consult, $admin, $ticket, $clock) {
            $ack = $ticket->messages()->create([
                'who' => 'admin',
                'name' => 'الإدارة',
                'role' => 'اعتماد',
                'body' => '<p>اعتمدت الإدارة ملخّص الجلسة، وهذه نتيجة ملفّكم.</p>',
                'time_label' => $clock,
            ]);

            $card = $ticket->messages()->create([
                'who' => 'ai',
                'name' => LegalAiService::AGENT_NAME,
                'role' => 'النتيجة',
                'body' => TicketResult::card($ticket, $ticket->summary),
                'time_label' => $clock,
            ]);

            if ($ticket->summary !== null) {
                Workflow::run(new ApproveTicketResult, $ticket->summary, $admin, [
                    'result' => TicketResult::compose($ticket, $ticket->summary),
                ]);
            }

            Workflow::run(new ReadyForOutcome, $ticket, $admin);

            DB::afterCommit(function () use ($consult, $admin, $ticket, $ack, $card) {
                Audit::log(
                    action: 'اعتماد ملخّص الجلسة وجاهزية قرار المآل',
                    description: "اعتمد {$admin->name} ملخّص جلسة الاستشارة {$consult->ref} ونُقلت التذكرة {$ticket->number} إلى جاهزية اتخاذ قرار المآل النهائي.",
                    category: 'تذاكر',
                    auditable: $ticket,
                    auditableRef: $ticket->number,
                    afterState: ['الحالة' => $ticket->status],
                );

                Live::push(new TicketMessageBroadcast($ack));
                Live::push(new TicketMessageBroadcast($card));

                Notify::send($ticket->user_id, 'check', 't-green', "اكتملت معالجة تذكرتك {$ticket->number} — ملخّص الجلسة والقرارات متاحة داخل التذكرة وفي «استشاراتي».");
            });
        });
    }
}
