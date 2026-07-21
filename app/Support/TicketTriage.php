<?php

namespace App\Support;

use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\UserNotification;
use App\Services\LegalAiService;

/**
 * الوكيل التشغيلي الذكي للتذاكر — يؤتمت المراحل المبكرة (تصنيف، طلب مستندات، إحالة)
 * ويُبقي الاعتمادات القانونية (المستشار/الإدارة) بيد البشر.
 * كل إجراء آلي يوثَّق بملاحظة داخلية يراها الموظفون دون العميل.
 */
class TicketTriage
{
    public const AGENT = 'الوكيل الذكي';

    public static function enabled(): bool
    {
        return (bool) config('services.ai_agent.enabled');
    }

    /**
     * عند فتح التذكرة: تصنيف آلي للقسم/الأولوية، تقدّم عبر «قيد الاستلام → قيد التحليل»
     * برسائل الرحلة نفسها، ثم طلب مستندات نوع الخدمة فوراً → «بانتظار مستندات».
     */
    public static function onOpened(Ticket $ticket, string $details): void
    {
        if (! self::enabled()) {
            return;
        }

        // تصنيف صامت (لا رسائل محادثة) — يُضبط القسم داخلياً فقط
        $triage = app(LegalAiService::class)->triageTicket($ticket, $details);
        if (empty($ticket->department) && $triage['department'] !== '') {
            $ticket->update(['department' => $triage['department']]);
        }

        // رسالة ترحيب واحدة إنسانية تجمع الترحيب + إعادة الصياغة + طلب المستندات
        $docs = ServiceDocs::for($ticket->type);
        $greeting = app(LegalAiService::class)->greet($ticket, $details, $docs);
        $chips = implode('', array_map(fn ($d) => '<span class="doc-chip">'.e($d).'</span>', $docs));

        $ticket->update(['status' => 'بانتظار مستندات', 'tone' => TicketJourney::toneFor('بانتظار مستندات'), 'last_message' => 'بانتظار إرفاق المستندات المطلوبة', 'date_label' => 'الآن']);
        $msg = $ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => LegalAiService::AGENT_ROLE,
            'body' => '<p>'.nl2br(e($greeting)).'</p><div class="doc-list">'.$chips.'</div>',
            'time_label' => self::clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));

        self::audit($ticket, sprintf(TicketTexts::AUDIT_AUTO_TRIAGE, $ticket->department ?: 'غير محدد', $triage['priority']));
        Live::push(new TicketStatusBroadcast($ticket));
    }

    /**
     * عند إرفاق مستند: فحص محتواه الفعلي والتحقق من ارتباطه بموضوع التذكرة.
     * لا إحالة إلا بمستند مفهوم ومرتبط؛ غير المرتبط يُرفض ويُطلب الصحيح؛
     * والمتعذّر فحصه يُترك لمراجعة الموظف اليدوية (لا تقدّم آلي أعمى).
     */
    public static function onDocumentAttached(Ticket $ticket, ?TicketDocument $doc = null): void
    {
        if (! self::enabled()) {
            return;
        }

        if ($ticket->status !== 'بانتظار مستندات') {
            $analysis = $doc ? app(LegalAiService::class)->analyzeDocument($ticket, $doc) : null;
            if ($analysis === null) {
                $doc?->update(['status' => 'بحاجة لمراجعة يدوية']);
                self::audit($ticket, 'تنبيه: تم إرفاق مستند جديد «'.($doc->name ?? '—').'» وتعذر فحصه آلياً — يحتاج مراجعة يدوية.');

                return;
            }

            $doc->update([
                'status' => $analysis['related'] ? 'مرتبط' : 'غير مرتبط',
                'doc_type' => $analysis['doc_type'],
                'summary' => $analysis['summary'],
                'reason' => $analysis['reason'],
                'summary_approved' => null, // بانتظار اعتماد الموظف
            ]);

            // ملاحظة داخلية للموظف مع زر الاعتماد — لا ترى العميل قبل الاعتماد
            $pendingBody = '<div data-approve-doc-id="'.e($doc->id).'" data-ticket-no="'.e($ticket->number).'"'
                .' style="display:contents">'
                .'<p>🔍 اكتمل تحليل المستند الإضافي «'.e($doc->name).'» — بانتظار اعتمادك لإرسال الملخص للعميل.</p>'
                .'<div class="doc-list" style="flex-direction:column;align-items:stretch">'
                .'<span class="doc-chip">📄 النوع: '.e($analysis['doc_type']).'</span>'
                .($analysis['summary'] !== '' ? '<span class="doc-chip">📝 '.e($analysis['summary']).'</span>' : '')
                .'</div></div>';

            $msg = $ticket->messages()->create([
                'who' => 'note',
                'name' => LegalAiService::AGENT_NAME,
                'role' => 'ملخص بانتظار الاعتماد',
                'body' => $pendingBody,
                'time_label' => self::clock(),
            ]);
            Live::push(new TicketMessageBroadcast($msg));

            return;
        }

        $analysis = $doc ? app(LegalAiService::class)->analyzeDocument($ticket, $doc) : null;

        // تعذّر الفحص (لا مزوّد/نوع غير مدعوم/ملف كبير) — يبقى القرار للموظف
        if ($analysis === null) {
            $doc?->update(['status' => 'بحاجة لمراجعة يدوية']);
            self::requestHuman($ticket, sprintf(TicketTexts::AUDIT_FAILED_ANALYSIS, $doc->name ?? '—'));

            return;
        }

        // مستند غير مرتبط بالموضوع — رفض مع التوضيح وطلب المستندات الصحيحة
        if (! $analysis['related']) {
            $doc->update(['status' => 'غير مرتبط', 'doc_type' => $analysis['doc_type'], 'summary' => $analysis['summary'], 'reason' => $analysis['reason']]);

            $chips = implode('', array_map(fn ($d) => '<span class="doc-chip">'.e($d).'</span>', ServiceDocs::for($ticket->type)));
            $msg = $ticket->messages()->create([
                'who' => 'ai',
                'name' => LegalAiService::AGENT_NAME,
                'role' => 'نواقص',
                'body' => '<p>فحصنا المستند «'.e($doc->name).'» وتبيّن أنه <b>غير مرتبط بموضوع تذكرتك</b>'
                    .($analysis['reason'] !== '' ? ' — '.e($analysis['reason']) : '.')
                    .'</p><p>نأمل إرفاق المستندات الصحيحة التالية:</p><div class="doc-list">'.$chips.'</div>',
                'time_label' => self::clock(),
            ]);
            Live::push(new TicketMessageBroadcast($msg));
            self::audit($ticket, 'رُفض المستند «'.$doc->name.'» (النوع المكتشف: '.$analysis['doc_type'].') لعدم ارتباطه بالموضوع — طُلب من العميل المستند الصحيح.');

            return;
        }

        // مستند مفهوم ومرتبط — الملخص يُعرض أولاً للموظف للاعتماد، ثم الإحالة الآلية
        $doc->update([
            'status' => 'مرتبط',
            'doc_type' => $analysis['doc_type'],
            'summary' => $analysis['summary'],
            'reason' => $analysis['reason'],
            'summary_approved' => null, // بانتظار اعتماد الموظف
        ]);

        // ملاحظة داخلية للموظف مع زر الاعتماد — لا ترى العميل قبل الاعتماد
        $pendingBody = '<div data-approve-doc-id="'.e($doc->id).'" data-ticket-no="'.e($ticket->number).'"'
            .' style="display:contents">'
            .'<p>🔍 اكتمل تحليل المستند «'.e($doc->name).'» — بانتظار اعتمادك لإرسال الملخص للعميل.</p>'
            .'<div class="doc-list" style="flex-direction:column;align-items:stretch">'
            .'<span class="doc-chip">📄 النوع: '.e($analysis['doc_type']).'</span>'
            .($analysis['summary'] !== '' ? '<span class="doc-chip">📝 '.e($analysis['summary']).'</span>' : '')
            .'</div></div>';

        $msg = $ticket->messages()->create([
            'who' => 'note',
            'name' => LegalAiService::AGENT_NAME,
            'role' => 'ملخص بانتظار الاعتماد',
            'body' => $pendingBody,
            'time_label' => self::clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));

        self::referToLawyer($ticket);
        self::audit($ticket, 'فُحص المستند «'.$doc->name.'» ('.$analysis['doc_type'].') وثبت ارتباطه — الملخص بانتظار اعتماد الموظف قبل إرساله للعميل.');
    }

    /** عند رسالة عميل: كشف نية شكوى/استعجال → ملاحظة تصعيد داخلية للموظفين. */
    public static function onClientMessage(Ticket $ticket, string $message): void
    {
        if (! self::enabled()) {
            return;
        }

        foreach (TicketTexts::ESCALATION_WORDS as $word) {
            if (mb_stripos($message, $word) !== false) {
                self::requestHuman($ticket, "رسالة العميل تتضمن ما يشير إلى شكوى/استعجال («{$word}») — متابعة بشرية عاجلة.");

                return;
            }
        }
    }

    /**
     * الإحالة للقسم القانوني: إسناد محامٍ حقيقي (FK) + ملخص الملف الرباعي + انتظار اعتماد المستشار.
     * مشتركة بين مسار الموظف اليدوي (advance) والوكيل الآلي.
     */
    public static function referToLawyer(Ticket $ticket): void
    {
        if (! $ticket->relationLoaded('assignedLawyer') && $ticket->assigned_lawyer_id) {
            $ticket->load('assignedLawyer');
        }
        $lawyer = $ticket->assignedLawyer ?? TicketAssignment::assign($ticket);

        // مؤقتاً: تعليق نداء الذكاء الاصطناعي في الإحالة — تقدّم فوري بيد الموظف،
        // والمحامي يراجع الملخّص القالبي الحتمي ويعتمده. (يُعاد `summarize()` لإعادة التفعيل.)
        $parts = app(LegalAiService::class)->fallbackSummary($ticket);
        $ticket->summary()->updateOrCreate([], [
            'lawyer_id' => $lawyer?->id,
            'case_summary' => $parts['case_summary'],
            'attachments_summary' => $parts['attachments_summary'],
            'facts' => $parts['facts'],
            'key_points' => $parts['key_points'],
            'status' => 'awaiting_lawyer',
            'approved_at' => null,
        ]);

        $dept = $ticket->department ?: 'القسم القانوني المختص';
        $ticket->update([
            'assigned_lawyer' => $lawyer?->name ?: $ticket->assigned_lawyer,
            'assigned_lawyer_id' => $lawyer?->id ?: $ticket->assigned_lawyer_id,
            'status' => 'بانتظار اعتماد المستشار',
            'tone' => TicketJourney::toneFor('بانتظار اعتماد المستشار'),
            'last_message' => 'تمت الإحالة، وجارٍ اعتماد ملخص الملف من المستشار',
            'date_label' => 'الآن',
        ]);

        $msg = $ticket->messages()->create([
            'who' => 'staff',
            'name' => 'خدمة العملاء',
            'role' => 'إحالة',
            'body' => "تمت إحالة طلبكم إلى {$dept} لدراسة الموضوع، وجهّز الفريق القانوني ملخص الملف، وهو الآن بانتظار اعتماد المستشار القانوني.",
            'time_label' => self::clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));
        Live::push(new TicketStatusBroadcast($ticket));
    }

    /**
     * إشارة «حاجة لتدخّل بشري»: الوكيل يعمل مستقلاً، وعند تعذّر إكمال المهمة آلياً يرفع علماً
     * لموظفي الفرع (ملاحظة داخلية) ويُطمئن العميل بأن أحد الموظفين سيتابع طلبه.
     */
    public static function requestHuman(Ticket $ticket, string $reason): void
    {
        self::audit($ticket, sprintf(TicketTexts::AUDIT_HUMAN_REQUIRED, $reason));

        UserNotification::create([
            'user_id' => $ticket->user_id,
            'icon' => 'user',
            'tone' => 't-amber',
            'body' => sprintf(TicketTexts::NOTIFICATION_HUMAN_REQUIRED, $ticket->number),
            'time_label' => 'الآن',
            'is_read' => false,
        ]);
    }

    /** توثيق إجراء آلي كملاحظة داخلية (لا يراها العميل — تُستثنى who=note من صفحته). */
    private static function audit(Ticket $ticket, string $text): void
    {
        $msg = $ticket->messages()->create([
            'who' => 'note',
            'name' => self::AGENT,
            'role' => 'إجراء آلي',
            'body' => '<p>'.e($text).'</p>',
            'time_label' => self::clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));
    }

    private static function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
