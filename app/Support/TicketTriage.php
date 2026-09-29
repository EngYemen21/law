<?php

namespace App\Support;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Domain\Journey\Transitions\Ticket\AwaitTicketDocuments;
use App\Domain\Journey\Transitions\Ticket\ReferTicketToLawyer;
use App\Domain\Journey\Transitions\Ticket\TicketDocumentsReceived;
use App\Domain\Journey\Workflow;
use App\Enums\AiSource;
use App\Events\TicketMessageBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Jobs\EscalateUnassignedTicketJob;
use App\Jobs\GenerateTicketSummaryJob;
use App\Models\AiRun;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\User;
use App\Services\Ai\AiRunLogger;
use App\Services\LegalAiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * الوكيل التشغيلي الذكي للتذاكر — يؤتمت المراحل المبكرة (تصنيف، طلب مستندات، إحالة)
 * ويُبقي الاعتمادات القانونية (المستشار/الإدارة) بيد البشر.
 * كل إجراء آلي يوثَّق بملاحظة داخلية يراها الموظفون دون العميل.
 */
class TicketTriage
{
    public const AGENT = 'الوكيل الذكي';

    /**
     * **المراحل التي تُقبل فيها إحالة الموظّف — مصدرٌ واحد.** يقرأها حارس المتحكّم
     * (`Employee\TicketController::advance`)، وتطابقها `REFERRABLE` في
     * `pages/employee/ticketchat.tsx` (إظهار الزرّ). و`referToLawyer` نفسها أوسع: تُنادى من
     * حالاتٍ أخرى ومنها نصوصٌ قديمة، فحارسها ضدّ التكرار لا ضدّ المرحلة.
     */
    public const REFERRABLE = ['جديدة', 'قيد التحليل', 'محالة للقسم القانوني', 'بانتظار مستندات'];

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

        // idempotent: إعادة تشغيل الطابور كانت تبعث رسالة ترحيب ثانية للعميل وتُنشئ
        // قيد فرزٍ ثانياً. قيد `ai_runs` للتذكرة هو المفتاح الدائم — ويُكتب قبل أي
        // رسالة، فلا تبقى نافذة تعطّل يتكرّر فيها المخرج.
        if (AiRun::alreadyRan('triage', $ticket)) {
            return;
        }

        // تصنيف صامت (لا رسائل محادثة) — يُضبط القسم داخلياً فقط
        $triage = app(LegalAiService::class)->triageTicket($ticket, $details);
        // يُكتب قسم الذكاء فقط إن طابق قسماً في الكتالوج — كان يكتب أيّ اسمٍ يُرجعه النموذج،
        // فيدخل البياناتِ قسمٌ لا يعرفه الإسناد ولا المرشّحات
        $department = empty($ticket->department) ? LegalCatalogue::resolveDepartment($triage['department']) : null;
        if ($department !== null) {
            $ticket->update(['department' => $department->name, 'legal_department_id' => $department->id]);
        }

        // مصدر الفرز يُسجَّل دائماً: الاحتياطيّ يعيد قسم العميل نفسه بأولوية «عادية»
        // ثابتة — تسجيله كأنه «فرز آليّ» كان يوهم بأن نموذجاً صنّف التذكرة.
        $triageSource = AiSource::tryFrom((string) ($triage['source'] ?? AiSource::AiSuccess->value)) ?? AiSource::AiSuccess;
        $meta = is_array($triage['meta'] ?? null) ? $triage['meta'] : [];
        // `number` لا `ref`: التذكرة لا تملك `ref` أصلاً، فكان القيد يُحفظ بمرجعٍ فارغ
        // والمراجع يرى «—» فلا يعرف أيّ تذكرة يراجع. (الاستشارة والتنفيذ يملكان
        // الحقلين فعلاً، فالعطب كان في التذاكر وحدها.)
        // والفرز متوسّط الحساسيّة: يُقبل آلياً فوق العتبة ويُصعَّد دونها أو بلا قياس.
        AiRunLogger::log('triage', $triageSource, $meta, $ticket, (string) $ticket->number, policyTask: 'ticket.triage');

        // إن أرفق العميل مستندات عند الفتح: تُحلَّل فعلياً أولاً فيتفرّع الردّ بحسب صلتها بالموضوع
        $docs = $ticket->documents()->get();
        if ($docs->isNotEmpty()) {
            self::onOpenedWithDocuments($ticket, $details, $docs, $triage);

            return;
        }

        // لا مرفقات — رسالة ترحيب واحدة إنسانية تجمع الترحيب + إعادة الصياغة + طلب المستندات.
        // المطلوب من قائمة قسم التذكرة (`TicketDocumentRequirements`) لا من نوعها
        $ai = app(LegalAiService::class);
        $greeting = $ai->greet($ticket, $details, TicketDocumentRequirements::labels(TicketDocumentRequirements::missing($ticket)));
        // ترحيبٌ يصل العميل: يُقيَّد كبقيّة مخرجات المحادثة (حساسيّته `low` ⇒ `completed`)
        $greetRun = $ai->takeLastChatOutcome();
        AiRunLogger::log('chat.reply', $greetRun['source'], $greetRun['meta'], $ticket, (string) $ticket->number);

        self::move(new AwaitTicketDocuments, $ticket, 'بانتظار إرفاق المستندات المطلوبة', 'triage.greeting');
        $msg = $ticket->messages()->create([
            'who' => 'ai',
            'name' => LegalAiService::AGENT_NAME,
            'role' => LegalAiService::AGENT_ROLE,
            // الترحيب نفسه يطلب المستندات — فلا مقدّمة ثانية قبل القائمة
            'body' => TicketDocumentRequirements::requestHtml($ticket, null, '<p>'.nl2br(e($greeting)).'</p>'),
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
            $ackAi = app(LegalAiService::class);
            $ack = $ackAi->acknowledgeDocs($ticket, $details, $related);
            $ackRun = $ackAi->takeLastChatOutcome();
            AiRunLogger::log('chat.reply', $ackRun['source'], $ackRun['meta'], $ticket, (string) $ticket->number);
            $msg = $ticket->messages()->create([
                // `ai` لا `staff`: النصّ مولَّد آلياً (`acknowledgeDocs`) ولم يكتبه موظّف.
                // كان الوسم يجعل العميل يقرأ ردّاً آلياً منسوباً إلى فريقٍ بشريّ —
                // وهو ما يمنعه مبدأ صدق المصدر.
                'who' => 'ai',
                'name' => LegalAiService::AGENT_NAME,
                'role' => 'خدمة العملاء',
                'body' => '<p>'.nl2br(e($ack)).'</p>',
                'time_label' => self::clock(),
            ]);
            Live::push(new TicketMessageBroadcast($msg));

            // نُقلت إلى «قيد التحليل» لتظهر للموظف ويقوم هو بالإحالة يدوياً عبر زر الإحالة (منع الإحالة التلقائية للـ AI)
            self::move(new TicketDocumentsReceived, $ticket, 'تم استلام المستندات والتحقق منها، وهي بانتظار مراجعة الموظف وإحالتها للمستشار', 'triage.opened_with_documents');
            Live::push(new TicketStatusBroadcast($ticket));

            self::audit($ticket, sprintf(TicketTexts::AUDIT_AUTO_TRIAGE, $ticket->department ?: 'غير محدد', $triage['priority']));
            self::audit($ticket, 'فُتحت التذكرة بمستندات ذات صلة ('.count($related).') — تم التحقق منها ونُقلت إلى «قيد التحليل» بانتظار إحالة الموظف للمستشار.');

            return;
        }

        // (ب) مرفقات فُحصت لكن لا شيء ذو صلة — طلب المستندات الصحيحة
        if ($analyzedAny) {
            self::move(new AwaitTicketDocuments, $ticket, 'بانتظار إرفاق المستندات الصحيحة', 'triage.unrelated_documents');
            $msg = $ticket->messages()->create([
                'who' => 'ai',
                'name' => LegalAiService::AGENT_NAME,
                'role' => 'نواقص',
                'body' => TicketDocumentRequirements::requestHtml(
                    $ticket,
                    'لبدء الدراسة، نأمل إرفاق المستندات التالية:',
                    '<p>شكراً لك، اطّلعنا على المستندات المرفقة إلا أنها لا تخصّ موضوع تذكرتك مباشرةً.</p>',
                ),
                'time_label' => self::clock(),
            ]);
            Live::push(new TicketMessageBroadcast($msg));
            self::audit($ticket, 'فُتحت التذكرة بمستندات غير مرتبطة بالموضوع — طُلبت المستندات الصحيحة.');
            Live::push(new TicketStatusBroadcast($ticket));

            return;
        }

        // (ج) تعذّر فحص كل المرفقات — إقرار بالاستلام (دون إعادة طلب بجفاء) + مراجعة يدوية
        self::move(new AwaitTicketDocuments, $ticket, 'المستندات المرفقة قيد المراجعة', 'triage.unreadable_documents');
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

        // المستندات المرفقة من المكتب أو غير المرفوعة من العميل لا تخضع لإجراءات AI
        if ($doc && ! $doc->isFromClient()) {
            return;
        }

        // لا تحليلَ على ملفٍّ منتهٍ — كان يُحلَّل ويُرسل ملخّصه على تذكرةٍ مغلقة (ع٢٤)
        if ($ticket->isTerminal()) {
            return;
        }

        if ($ticket->status !== 'بانتظار مستندات') {
            $analysis = $doc ? app(LegalAiService::class)->analyzeDocument($ticket, $doc) : null;

            // **الفرع الشائع كان بلا قيد.** نظيرُه المقيَّد (`classifyDoc`) هو
            // الاستثناء: يقع حين تكون التذكرة «بانتظار مستندات» وحدها. أمّا هذا —
            // إرفاق مستندٍ في أيّ وقتٍ آخر — فيكتب حكماً يوجّه الملفّ («مرتبط»
            // يُحيله للمحامي، و«غير مرتبط» يطلب من العميل غيره) بلا أثرٍ يُراجَع.
            if ($doc !== null) {
                AiRunLogger::log(
                    'document.analyze',
                    $analysis === null ? AiSource::ManualRequired : AiSource::AiSuccess,
                    is_array($analysis['meta'] ?? null) ? $analysis['meta'] : [],
                    $ticket,
                    (string) $ticket->number,
                );
            }

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
            self::recordRequirements($doc, $analysis);

            // **ملخّص المستند المرتبط وحده يصل العميل** — كان يُرسل حتى لغير المرتبط (ع٢٤)
            if ($analysis['related']) {
                self::sendDocSummary($ticket, $doc);
            }

            // ولا يُعاد توليد ملخّصٍ اعتُمد أو حرّره المستشار بيده (ع٢٦)
            if ($analysis['related'] && $ticket->summary
                && $ticket->summary->status === 'awaiting_lawyer'
                && $ticket->summary->edited_at === null) {
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
            $msg = $ticket->messages()->create([
                'who' => 'ai',
                'name' => LegalAiService::AGENT_NAME,
                'role' => 'نواقص',
                'body' => TicketDocumentRequirements::requestHtml(
                    $ticket,
                    'نأمل إرفاق المستندات الصحيحة التالية:',
                    '<p>فحصنا المستند «'.e($doc->name).'» وتبيّن أنه <b>غير مرتبط بموضوع تذكرتك</b>'
                        .($analysis['reason'] !== '' ? ' — '.e($analysis['reason']) : '.').'</p>',
                ),
                'time_label' => self::clock(),
            ]);
            Live::push(new TicketMessageBroadcast($msg));
            self::audit($ticket, 'رُفض المستند «'.$doc->name.'» (النوع المكتشف: '.$analysis['doc_type'].') لعدم ارتباطه بالموضوع — طُلب من العميل المستند الصحيح.');

            return;
        }

        // مستند مفهوم ومرتبط — يُرسل الملخص للعميل ونقل التذكرة إلى «قيد التحليل» بانتظار إحالة الموظف (منع الإحالة التلقائية للـ AI)
        self::sendDocSummary($ticket, $doc);

        self::move(new TicketDocumentsReceived, $ticket, 'تم استلام المستند المطلوب، والتذكرة بانتظار مراجعة الموظف وإحالتها للمستشار', 'triage.document_attached');
        Live::push(new TicketStatusBroadcast($ticket));

        self::audit($ticket, 'فُحص المستند «'.$doc->name.'» ('.$analysis['doc_type'].') وثبت ارتباطه — أُرسل ملخصه للعميل ونُقلت التذكرة إلى «قيد التحليل» بانتظار إحالة الموظف للمستشار.');
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

        // فحص المستند **يُسجَّل كأيّ مخرج ذكاء**. كان يجري بلا قيد إطلاقاً، فلا يظهر في
        // المؤشّرات ولا الكلفة ولا صندوق المراجعة — بينما حكمُه يوجّه الملفّ: «مرتبط»
        // يُحيل التذكرة للمحامي، و«غير مرتبط» يطلب من العميل مستندات أخرى. قرارٌ
        // يغيّر مسار الطلب بلا أثرٍ يُراجَع.
        if ($doc !== null) {
            $meta = is_array($analysis['meta'] ?? null) ? $analysis['meta'] : [];
            $source = $analysis === null ? AiSource::ManualRequired : AiSource::AiSuccess;

            AiRunLogger::log('document.analyze', $source, $meta, $ticket, (string) $ticket->number);
        }

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
        self::recordRequirements($doc, $analysis);

        return $analysis;
    }

    /**
     * **حصيلة الفحص مقابل قائمة مستندات القسم** — الموضع الواحد لفرعَي الفحص (`classifyDoc` والإرفاق
     * خارج «بانتظار مستندات»). المستند غير المرتبط لا يستوفي بنداً ولو سمّاه النموذج؛ ويُعلَّم مفحوصاً
     * في الحالين، فلا يبقى «لم يُتحقّق» ما فُحص فعلاً.
     *
     * @param  array{related: bool, requirements?: mixed}  $analysis
     */
    private static function recordRequirements(TicketDocument $doc, array $analysis): void
    {
        TicketDocumentRequirements::recordAiCheck($doc, $analysis['related'] ? ($analysis['requirements'] ?? []) : []);
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

        // التأكد من أن الحدث لرسالة عميل فقط (منع التفاعل مع رسائل الموظف أو المحامي أو الإدارة)
        if (auth()->check() && ! auth()->user()->isClient()) {
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
     *
     * $actor: من أحال (يُقيَّد في سجلّ الانتقالات)؛ `null` لقرار النظام.
     */
    public static function referToLawyer(Ticket $ticket, ?User $actor = null): void
    {
        if (! $ticket->relationLoaded('assignedLawyer') && $ticket->assigned_lawyer_id) {
            $ticket->load('assignedLawyer');
        }

        /*
         * **لا اختيارَ صامتاً لمحامٍ** (قرار المالك 2026-09-20): الإسناد بشريّ — من شاشة «تحويل
         * التذاكر» أو «توزيع التذاكر». وكان هذا السطر يختار المختصّ عند الإحالة إن لم يكن مسنَداً.
         * وإن أحال الموظّف ولا محامي عليها، تُصعَّد للإدارة العليا وتُسنَد لها (مباشرةً لا في
         * الطابور: الإحالة تحتاج صاحبَ ملفٍّ الآن)، فلا تبقى بلا صاحب ولا تُختار لها جهةٌ بالصدفة.
         */
        if ($ticket->assignedLawyer === null) {
            EscalateUnassignedTicketJob::dispatchSync($ticket->id);
            $ticket->refresh()->load('assignedLawyer');
        }

        $lawyer = $ticket->assignedLawyer;

        /*
         * **الإحالة خطوةٌ واحدة لا تنقسم.** كانت تكتب الملخّص ثمّ تنقل الحالة بلا قفلٍ ولا معاملة:
         * نقرتان متسارعتان على الزرّ تمرّان معاً من بوّابة المتحكّم (تُفحص قبل أيّ قفل)، فتريان
         * الملفّ بلا ملخّص فتُنشئان ملخّصَين ورسالتَي إحالة للعميل؛ وفشلُ نقل الحالة كان يترك
         * الملخّص مكتوباً. الآن: قفل الصفّ ← إعادة فحص المرحلة تحت القفل ← الكتابات كلّها في معاملة.
         *
         * والآثار الخارجيّة خارج المعاملة عمداً: التصعيد أعلاه (بريد وإشعارات)، والبثّ والإشعار
         * والمهمّة أدناه — فلا يُشعَر أحدٌ بما قد يُلغى، ولا يُحتجَز القفل في انتظار الشبكة.
         */
        $upgrade = false; // كُتب ملخّصٌ قالبيّ ⇒ تُطلق مهمّة ترقيته بعد الالتزام
        $msg = DB::transaction(function () use ($ticket, $actor, $lawyer, &$upgrade) {
            $locked = Ticket::whereKey($ticket->id)->lockForUpdate()->first();
            if ($locked === null) {
                return null;
            }

            $existing = $locked->summary()->first();

            /*
             * نقرةٌ ثانية انتظرت القفل: الأولى أحالت فعلاً (الحالة «بانتظار اعتماد المستشار» ومعها
             * ملخّص). ولا يُقاس على قائمة مراحل الزرّ: `referToLawyer` عامّة تُنادى من حالاتٍ أخرى
             * ومنها نصوصٌ قديمة، و`from()` في الانتقال مفتوحةٌ عمداً لذلك — فالمقيس هو التكرار نفسه.
             */
            if ((string) $locked->status === TicketStatus::AwaitingLawyerApproval->value && $existing !== null) {
                self::audit($locked, 'إحالة مكرّرة — أُحيل الملفّ فعلاً، فلم يُكتب ملخّصٌ ثانٍ ولا رسالةٌ ثانية.');

                return null;
            }

            // **لا تُمحى مراجعةٌ وقعت** — ملخّصٌ اعتمده المستشار أو الإدارة لا يُستبدل بقالب ولا تُعاد
            // التذكرة خلفه (ع١١). الإحالة المكرّرة على ملفٍّ معتمد لا تفعل شيئاً.
            if ($existing !== null && $existing->status !== 'awaiting_lawyer') {
                self::audit($locked, 'إحالة مكرّرة على ملخّصٍ اعتُمد — لم يُمسّ الملخّص ولا الحالة.');

                return null;
            }

            if ($existing !== null && ($existing->ai_generated || $existing->edited_at !== null)) {
                // مسوّدةٌ حقيقيّة (تحليلٌ أو تحرير المستشار) لا يدهسها قالب
                $existing->update(['lawyer_id' => $lawyer?->id ?? $existing->lawyer_id]);
            } else {
                // نائب فوري: ملخّص قالبي حتمي يظهر لحظياً كي تبقى الإحالة سريعة بيد الموظف،
                // ثم تُرقّيه GenerateTicketSummaryJob لتحليل ذكاء اصطناعي حقيقي مبنيّ على المستندات.
                $parts = app(LegalAiService::class)->fallbackSummary($locked);
                $draft = [
                    'lawyer_id' => $lawyer?->id,
                    'case_summary' => $parts['case_summary'],
                    'attachments_summary' => $parts['attachments_summary'],
                    'facts' => $parts['facts'],
                    'key_points' => $parts['key_points'],
                    'approved_at' => null,
                    'ai_generated' => false, // نائب قالبي — تُرقّيه المهمّة لتحليل حقيقي
                ];

                if ($existing === null) {
                    // ميلاد الملخّص أوّل سطرٍ في رحلته — يُفتح بالمحرّك بحالته الأولى
                    Workflow::open(
                        'ticket_summary.opened',
                        fn () => $locked->summary()->create($draft + ['status' => 'awaiting_lawyer']),
                        $actor,
                    );
                } else {
                    // قائمٌ «بانتظار المستشار» أصلاً (ما سواه عاد أعلاه) — يُستبدل نصّه والحالة كما هي
                    $existing->update($draft);
                }
                $upgrade = true;
            }

            $dept = $locked->department ?: 'القسم القانوني المختص';
            Workflow::run(new ReferTicketToLawyer, $locked, $actor, [
                'lawyer_id' => $lawyer?->id,
                'lawyer_name' => $lawyer?->name,
            ]);

            return $locked->messages()->create([
                // إشعارٌ آليّ بقالب ثابت — لم يكتبه موظّف، فلا يُنسب إلى فريقٍ بشريّ
                'who' => 'ai',
                'name' => 'خدمة العملاء',
                'role' => 'إحالة',
                // **لا يُنسب إلى الفريق ما لم يكتبه.** ما حُفظ للتوّ قالبٌ حتميّ بـ`ai_generated = false`،
                // والتلخيص الحقيقيّ في الطابور بعدُ. فقولُ «جهّز الفريق القانوني ملخص الملف» يُخبر
                // العميل بعملٍ لم يقع، ويُنسب إلى بشرٍ ما كتبه قالب.
                'body' => 'تمت إحالة طلبكم إلى '.e($dept).' لدراسة الموضوع، وهو الآن قيد الإعداد بانتظار اعتماد المستشار القانوني.',
                'time_label' => self::clock(),
            ]);
        });

        if ($msg === null) {
            return; // مكرّرة أو معتمدة — لا أثر خارجيّ
        }

        $ticket->refresh();
        if ($upgrade) {
            GenerateTicketSummaryJob::dispatch($ticket);
        }
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

    /**
     * خطوة الوكيل الآليّ في الرحلة — بالمحرّك، وبصمتٍ حيث لا يقبل الانتقالُ الحالة.
     *
     * كان الوكيل يكتب الحالة مباشرةً **بلا أيّ فحص** من مهمّةٍ في الطابور. `from()` في انتقاليه
     * واسعةٌ لتقبل كلّ ما كان ينجح؛ وما خارجها (ملفٌّ حُسم مآله قبل وصول المهمّة) يُتجاوز هنا بصمت
     * لا برفضٍ 422 يُسقط المهمّة ويعيدها — الوكيل لا يُحيي ملفّاً مغلقاً أو محوّلاً لقضيّة.
     *
     * $via: أيّ فرعٍ من فروع الوكيل كتب الخطوة — يُحفظ في سجلّ الانتقال للمراجعة.
     */
    private static function move(Transition $transition, Ticket $ticket, string $lastMessage, string $via): void
    {
        if (! $transition->accepts((string) $ticket->status)) {
            return;
        }

        Workflow::run($transition, $ticket, null, ['last_message' => $lastMessage, 'via' => $via]);
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
