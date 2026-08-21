<?php

namespace App\Support;

use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Jobs\GenerateTicketSummaryJob;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Services\LegalAiService;
use Illuminate\Support\Collection;

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
     * عند فتح التذكرة: تصنيف آلي للقسم/الأولوية، تقدّم عبر «جديدة → قيد التحليل»
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

        // إن أرفق العميل مستندات عند الفتح: تُحلَّل فعلياً أولاً فيتفرّع الردّ بحسب صلتها بالموضوع
        $docs = $ticket->documents()->get();
        if ($docs->isNotEmpty()) {
            self::onOpenedWithDocuments($ticket, $details, $docs, $triage);

            return;
        }

        // لا مرفقات — رسالة ترحيب واحدة إنسانية تجمع الترحيب + إعادة الصياغة + طلب المستندات
        $svcDocs = ServiceDocs::for($ticket->type);
        $greeting = app(LegalAiService::class)->greet($ticket, $details, $svcDocs);
        $chips = implode('', array_map(fn ($d) => '<span class="doc-chip">'.e($d).'</span>', $svcDocs));

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
     * فتح التذكرة مع مرفقات: يُحلَّل كل مستند فعلياً (analyzeDocument) ثم يتفرّع الردّ:
     * (أ) وُجد مستند ذو صلة → إقرار بنبرة الموظف المختص ثم إحالة تلقائية للقسم؛
     * (ب) مرفقات فُحصت بلا صلة → توضيح ودّي وطلب المستندات الصحيحة → «بانتظار مستندات»؛
     * (ج) تعذّر فحص الجميع → إقرار بالاستلام وأنها قيد المراجعة + رفع علم مراجعة يدوية.
     *
     * @param  Collection<int,TicketDocument>  $docs
     * @param  array{department:string,priority:string,intent:string}  $triage
     */
    private static function onOpenedWithDocuments(Ticket $ticket, string $details, $docs, array $triage): void
    {
        $related = [];
        $analyzedAny = false; // نجح فحص مستند واحد على الأقل؟

        foreach ($docs as $doc) {
            $analysis = self::classifyDoc($ticket, $doc);
            if ($analysis === null) {
                continue; // متعذّر الفحص — وُسم «بحاجة لمراجعة يدوية»
            }
            $analyzedAny = true;
            if ($analysis['related']) {
                $related[] = ['name' => (string) $doc->name, 'summary' => (string) ($doc->summary ?? '')];
            }
        }

        // (أ) مستند ذو صلة على الأقل — إقرار الموظف المختص + إحالة تلقائية
        if ($related !== []) {
            $ack = app(LegalAiService::class)->acknowledgeDocs($ticket, $details, $related);
            $msg = $ticket->messages()->create([
                'who' => 'staff',
                'name' => LegalAiService::AGENT_NAME,
                'role' => 'خدمة العملاء',
                'body' => '<p>'.nl2br(e($ack)).'</p>',
                'time_label' => self::clock(),
            ]);
            Live::push(new TicketMessageBroadcast($msg));

            self::referToLawyer($ticket); // يولّد الملخّص + «بانتظار اعتماد المستشار» + بثّ + إشعار المستشار
            self::audit($ticket, sprintf(TicketTexts::AUDIT_AUTO_TRIAGE, $ticket->department ?: 'غير محدد', $triage['priority']));
            self::audit($ticket, 'فُتحت التذكرة بمستندات ذات صلة ('.count($related).') — أُقرّ باستلامها وأُحيلت التذكرة تلقائياً.');

            return;
        }

        // (ب) مرفقات فُحصت لكن لا شيء ذو صلة — طلب المستندات الصحيحة
        if ($analyzedAny) {
            $chips = implode('', array_map(fn ($d) => '<span class="doc-chip">'.e($d).'</span>', ServiceDocs::for($ticket->type)));
            $ticket->update(['status' => 'بانتظار مستندات', 'tone' => TicketJourney::toneFor('بانتظار مستندات'), 'last_message' => 'بانتظار إرفاق المستندات الصحيحة', 'date_label' => 'الآن']);
            $msg = $ticket->messages()->create([
                'who' => 'ai',
                'name' => LegalAiService::AGENT_NAME,
                'role' => 'نواقص',
                'body' => '<p>شكراً لك، اطّلعنا على المستندات المرفقة إلا أنها لا تخصّ موضوع تذكرتك مباشرةً.</p>'
                    .'<p>لبدء الدراسة، نأمل إرفاق المستندات التالية:</p><div class="doc-list">'.$chips.'</div>',
                'time_label' => self::clock(),
            ]);
            Live::push(new TicketMessageBroadcast($msg));
            self::audit($ticket, 'فُتحت التذكرة بمستندات غير مرتبطة بالموضوع — طُلبت المستندات الصحيحة.');
            Live::push(new TicketStatusBroadcast($ticket));

            return;
        }

        // (ج) تعذّر فحص كل المرفقات — إقرار بالاستلام (دون إعادة طلب بجفاء) + مراجعة يدوية
        $ticket->update(['status' => 'بانتظار مستندات', 'tone' => TicketJourney::toneFor('بانتظار مستندات'), 'last_message' => 'المستندات المرفقة قيد المراجعة', 'date_label' => 'الآن']);
        $msg = $ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => LegalAiService::AGENT_ROLE,
            'body' => '<p>شكراً لك، وصلنا طلبك والمستندات المرفقة وهي قيد المراجعة، وسيوافيك الفريق المختص بالمستجدات قريباً.</p>',
            'time_label' => self::clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));
        self::requestHuman($ticket, 'فُتحت التذكرة بمستندات تعذّر فحصها آلياً — تحتاج مراجعة يدوية.');
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
                'summary_approved' => true, // يُرسل للعميل مباشرة (أُلغي اعتماد الموظف)
            ]);

            self::sendDocSummary($ticket, $doc);

            if ($analysis['related'] && $ticket->summary && ! $ticket->summary->approved_at) {
                GenerateTicketSummaryJob::dispatch($ticket, force: true);
            }

            return;
        }

        $analysis = self::classifyDoc($ticket, $doc);

        // تعذّر الفحص (لا مزوّد/نوع غير مدعوم/ملف كبير) — يبقى القرار للموظف
        if ($analysis === null) {
            self::requestHuman($ticket, sprintf(TicketTexts::AUDIT_FAILED_ANALYSIS, $doc->name ?? '—'));

            return;
        }

        // مستند غير مرتبط بالموضوع — رفض مع التوضيح وطلب المستندات الصحيحة
        if (! $analysis['related']) {
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

        // مستند مفهوم ومرتبط — يُرسل الملخص للعميل مباشرة (أُلغي اعتماد الموظف) ثم الإحالة الآلية
        self::sendDocSummary($ticket, $doc);

        self::referToLawyer($ticket);
        self::audit($ticket, 'فُحص المستند «'.$doc->name.'» ('.$analysis['doc_type'].') وثبت ارتباطه — أُرسل ملخصه للعميل وأُحيلت التذكرة.');
    }

    /**
     * يحلّل مستنداً ويحدّث صفّه (الحالة/النوع/الملخص) ثم يعيد التحليل — دون بثّ أي رسالة.
     * مشترك بين onOpened (تجميع قرار الفتح) وonDocumentAttached (المستند اللاحق).
     * يعيد null عند تعذّر الفحص (يوسم المستند «بحاجة لمراجعة يدوية»).
     *
     * @return array{related:bool,doc_type:string,summary:string,reason:string}|null
     */
    private static function classifyDoc(Ticket $ticket, ?TicketDocument $doc): ?array
    {
        $analysis = $doc ? app(LegalAiService::class)->analyzeDocument($ticket, $doc) : null;

        if ($analysis === null) {
            $doc?->update(['status' => 'بحاجة لمراجعة يدوية']);

            return null;
        }

        $doc->update([
            'status' => $analysis['related'] ? 'مرتبط' : 'غير مرتبط',
            'doc_type' => $analysis['doc_type'],
            'summary' => $analysis['summary'],
            'reason' => $analysis['reason'],
            'summary_approved' => $analysis['related'], // المتعلّق فقط يُرسل ملخصه للعميل مباشرة
        ]);

        return $analysis;
    }

    /** يرسل ملخص تحليل المستند للعميل كرسالة مرئية لحظية (بلا اعتماد بشري). */
    private static function sendDocSummary(Ticket $ticket, TicketDocument $doc): void
    {
        $msg = $ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => 'تحليل المستند',
            'body' => '<p>تم فحص المستند «'.e($doc->name).'» والتحقق من محتواه.</p>'
                .'<div class="doc-list" style="flex-direction:column;align-items:stretch">'
                .'<span class="doc-chip">📄 النوع: '.e((string) $doc->doc_type).'</span>'
                .(! empty($doc->summary) ? '<span class="doc-chip">📝 '.e((string) $doc->summary).'</span>' : '')
                .'</div>',
            'time_label' => self::clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));
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

        // نائب فوري: ملخّص قالبي حتمي يظهر لحظياً كي تبقى الإحالة سريعة بيد الموظف،
        // ثم تُرقّيه GenerateTicketSummaryJob لتحليل ذكاء اصطناعي حقيقي مبنيّ على المستندات.
        $parts = app(LegalAiService::class)->fallbackSummary($ticket);
        $ticket->summary()->updateOrCreate([], [
            'lawyer_id' => $lawyer?->id,
            'case_summary' => $parts['case_summary'],
            'attachments_summary' => $parts['attachments_summary'],
            'facts' => $parts['facts'],
            'key_points' => $parts['key_points'],
            'status' => 'awaiting_lawyer',
            'approved_at' => null,
            'ai_generated' => false, // نائب قالبي — تُرقّيه المهمّة لتحليل حقيقي
        ]);
        GenerateTicketSummaryJob::dispatch($ticket);

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
            'body' => 'تمت إحالة طلبكم إلى '.e($dept).' لدراسة الموضوع، وجهّز الفريق القانوني ملخص الملف، وهو الآن بانتظار اعتماد المستشار القانوني.',
            'time_label' => self::clock(),
        ]);
        Live::push(new TicketMessageBroadcast($msg));
        Live::push(new TicketStatusBroadcast($ticket));

        // إشعار المستشار المسند لمراجعة الملخّص واعتماده (يشمل حالة إعادة الإحالة بعد استكمال النواقص)
        if ($lawyer) {
            Notify::send($lawyer->id, 'user', 't-amber', "التذكرة {$ticket->number} بانتظار اعتمادك لملخّص الملف.");
        }
    }

    /**
     * إشارة «حاجة لتدخّل بشري»: الوكيل يعمل مستقلاً، وعند تعذّر إكمال المهمة آلياً يرفع علماً
     * للموظفين (ملاحظة داخلية) ويُطمئن العميل بأن أحد الموظفين سيتابع طلبه.
     */
    public static function requestHuman(Ticket $ticket, string $reason): void
    {
        self::audit($ticket, sprintf(TicketTexts::AUDIT_HUMAN_REQUIRED, $reason));

        Notify::send($ticket->user_id, 'user', 't-amber', sprintf(TicketTexts::NOTIFICATION_HUMAN_REQUIRED, $ticket->number));
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
