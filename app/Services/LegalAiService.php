<?php

namespace App\Services;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\CaseDocument;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\ExecutionDocument;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\User;
use App\Services\Ai\AiCallResult;
use App\Services\Ai\AiConfidence;
use App\Services\Ai\AiContextBuilder;
use App\Services\Ai\AiFailure;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiModelRouter;
use App\Services\Ai\AiOutputValidator;
use App\Services\Ai\AiPromptRegistry;
use App\Services\Ai\AiUsage;
use App\Services\Ai\LegalKnowledge;
use App\Support\ServiceDocs;
use App\Support\WebTimeLimit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * المساعد القانوني الذكي — يولّد ردود «الدعم الفني» الحقيقية.
 * المزوّد الأساسي: Gemini (Google). الاحتياطي التلقائي عند غياب مفتاحه: GLM (z.ai).
 */
class LegalAiService
{
    /** هوية المُجيب كما تظهر للعميل (موظف دعم فني، لا «ذكاء اصطناعي»). */
    public const AGENT_NAME = 'خدمة العملاء';

    public const AGENT_ROLE = 'الدعم الفني';

    public function isConfigured(): bool
    {
        return ! empty(config('services.gemini.key'))
            || ! empty(config('services.glm.key'));
    }

    /**
     * هل يوجد مزوّد ذكاء اصطناعي مُهيّأ **وغير مهدّأ** الآن؟
     * تُميّز «لا مفتاح» (دائم) عن «تهدئة بعد نفاد الحصّة» (عابر) — تقودها المهام لقرار إعادة المحاولة.
     */
    public function available(): bool
    {
        // مصدر واحد للحكم: البوّابة تعرف ترتيب المزوّدين وحالة تهدئتهم. كان الشرط
        // مكرّراً هنا وفي `run()`، فأي تغيير في أحدهما يفارق الآخر صامتاً.
        return AiGateway::hasAvailableProvider();

        // الجسم السابق (مُعلَّق لا محذوف، كي لا يُعاد اختراع الشرط هنا):
        // if (! empty(config('services.gemini.key')) && ! Cache::has('ai:cooldown:gemini')) return true;
        // if (! empty(config('services.glm.key')) && ! Cache::has('ai:cooldown:glm')) return true;
        // return false;
    }

    /** يفتح قاطع الدائرة: يهدّئ المزوّد لفترة فلا يُستدعى عبثاً حتى تعود الحصّة. */
    private function cooldown(string $provider, int $minutes): void
    {
        Cache::put("ai:cooldown:{$provider}", true, now()->addMinutes($minutes));
        Log::warning("AI circuit breaker: {$provider} on cooldown for {$minutes}m (quota/overload).");
    }

    /**
     * يستخلص كائن JSON بمرونة وأمان عاليين حتى لو تمّ تغليفه بأسوار Markdown أو نصوص جانبية.
     */
    public static function parseJsonResponse(?string $raw): ?array
    {
        if (empty($raw)) {
            return null;
        }

        $clean = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($raw)));
        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/', $raw, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * يجهّز ملخص الملف الرباعي للمستشار (تلخيص القضية/المرفقات/الوقائع/النقاط المهمة).
     * يُعيد دائماً مصفوفة صالحة — يستخدم الذكاء الاصطناعي إن توفّر، وإلا قالباً احتياطياً.
     *
     * @return array{case_summary: string, attachments_summary: string, facts: string, key_points: string, ai_generated?: bool}
     */
    public function summarize(Ticket $ticket): array
    {
        $convo = $ticket->messages()
            ->where('who', '!=', 'note')
            ->orderBy('id')->get()
            ->map(fn ($m) => ($m->who === 'client' ? 'العميل: ' : 'الفريق: ').AiContextBuilder::prepare((string) $m->body))
            ->implode("\n");

        // نتائج الفحص الذكي للمستندات المرفوعة (إن وُجدت) — تُثري ملخص المرفقات
        $docs = $ticket->documents()->whereNotNull('summary')->get()
            ->map(fn ($d) => "- «{$d->name}» ({$d->doc_type}؛ {$d->status}): {$d->summary}")
            ->implode("\n");

        $prompt = "نوع القضية: {$ticket->type}\nالقسم: {$ticket->department}\nعدد المرفقات: {$ticket->attachments}"
            .($docs !== '' ? "\n\nالمستندات المفحوصة:\n{$docs}" : '')
            ."\n\nالمحادثة:\n{$convo}";

        try {
            $json = $this->run(AiPromptRegistry::ticketSummarySystem(), [['role' => 'user', 'content' => $prompt]], json: true, promptId: 'ticket.summary');

            if ($json && ($data = self::parseJsonResponse($json))) {
                if (! empty($data['case_summary'])) {
                    return [
                        'case_summary' => (string) ($data['case_summary'] ?? ''),
                        'attachments_summary' => (string) ($data['attachments_summary'] ?? ''),
                        'facts' => is_array($data['facts'] ?? null) ? implode("\n", array_map(fn ($x) => '• '.ltrim($x, '• '), $data['facts'])) : (string) ($data['facts'] ?? ''),
                        'key_points' => is_array($data['key_points'] ?? null) ? implode("\n", array_map(fn ($x) => '• '.ltrim($x, '• '), $data['key_points'])) : (string) ($data['key_points'] ?? ''),
                        'ai_generated' => true, // تحليل ذكاء اصطناعي حقيقي
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService summarize failed: '.$e->getMessage());
        }

        // تعذّر الـAI: قالب احتياطي مع علَم صريح أنه ليس تحليلاً حقيقياً
        return $this->fallbackSummary($ticket) + ['ai_generated' => false];
    }

    /**
     * تحليل ذكي للتذكرة عند التحويل (يطابق cfAnalysis): يقترح نوع القضية والقسم المختص.
     * يُعيد دائماً مصفوفة صالحة (يقع على نوع/قسم التذكرة عند التعذّر).
     *
     * @return array{type: string, department: string}
     */
    public function classifyCase(Ticket $ticket): array
    {
        $convo = $ticket->messages()->where('who', '!=', 'note')->orderBy('id')->get()
            ->map(fn ($m) => ($m->who === 'client' ? 'العميل: ' : 'الفريق: ').AiContextBuilder::prepare((string) $m->body))
            ->implode("\n");

        $system = AiPromptRegistry::caseClassifySystem();
        $prompt = "نوع التذكرة: {$ticket->type}\nالقسم الحالي: {$ticket->department}\n\nالمحادثة:\n{$convo}";

        try {
            $json = $this->run($system, [['role' => 'user', 'content' => $prompt]], json: true);
            if ($json) {
                $data = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $json)), true);
                if (is_array($data) && ! empty($data['type'])) {
                    return [
                        'type' => (string) $data['type'],
                        'department' => (string) ($data['department'] ?? $ticket->department),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService classifyCase failed: '.$e->getMessage());
        }

        return self::fallbackClassification($ticket);
    }

    /**
     * التصنيف الاحتياطي الحتمي — بلا أي نداء خارجي.
     *
     * يُستخرج كي يُنادى من موضعين بلا نسخ: من classifyCase عند تعذّر المزوّد، ومن
     * CaseConversion التي صارت تُنشئ القضية بهذه القيم فوراً ثم تُنقّحها مهمّة مطابورة —
     * فنداء AI متزامن كان يحبس طلب التحويل **12.3 ثانية** مقاسة، وحدّ FPM ثلاثون.
     *
     * @return array{type: string, department: string}
     */
    public static function fallbackClassification(Ticket $ticket): array
    {
        return ['type' => $ticket->type, 'department' => $ticket->department ?: 'الاستشارات القانونية'];
    }

    /**
     * فرز آلي عند فتح التذكرة (الوكيل التشغيلي): يقترح القسم المختص والأولوية ونية الطلب.
     * يُعيد دائماً مصفوفة صالحة — احتياط حتمي (قسم العميل + أولوية عادية) عند التعذّر.
     *
     * @return array{department: string, priority: string, intent: string, source: string}
     */
    public function triageTicket(Ticket $ticket, string $details): array
    {
        $system = AiPromptRegistry::ticketTriageSystem();
        $prompt = "نوع التذكرة: {$ticket->type}\nالقسم الذي اختاره العميل: ".($ticket->department ?: '—')."\nتفاصيل الطلب:\n{$details}";

        $call = new AiCallResult(text: null, traceId: (string) Str::uuid(), durationMs: 0, failureCode: AiFailure::PROVIDER_ERROR);
        $structureFailure = null;

        try {
            $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], json: true, promptId: 'ticket.triage');
            if ($call->succeeded()) {
                $data = self::parseJsonResponse($call->text);
                $structureFailure = $data === null ? AiFailure::INVALID_JSON : AiFailure::INVALID_STRUCTURE;
                $valid = AiOutputValidator::ticketTriage($data);
                if ($valid !== null) {
                    return $valid + [
                        'source' => AiSource::AiSuccess->value,
                        'meta' => self::callMeta($call, 'ticket.triage', null, AiConfidence::forTicketTriage(
                            department: $valid['department'],
                            details: $details,
                            clientChosenDepartment: (string) $ticket->department,
                            ticketType: (string) $ticket->type,
                        )),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService triageTicket failed: '.$e->getMessage());
        }

        // احتياط حتميّ: لا فرز جرى — القسم يبقى كما اختاره العميل والأولوية/النية افتراضيتان.
        // `source` يمنع تسجيل هذا في التدقيق كأنه «فرز آليّ» أجراه نموذج.
        return [
            'department' => (string) ($ticket->department ?: ''),
            'priority' => 'عادية',
            'intent' => 'عادي',
            'source' => AiSource::Fallback->value,
            'meta' => self::callMeta($call, 'ticket.triage', $structureFailure),
        ];
    }

    /**
     * فحص مستند مرفق: قراءة محتواه الفعلي وتحديد نوعه وملخصه وهل يرتبط بموضوع التذكرة.
     * النصوص وdocx تُستخرج مباشرة (كل المزوّدين)؛ PDF والصور عبر Gemini متعدد الوسائط.
     * يُعيد {related, doc_type, summary, reason} أو null عند تعذّر الفحص (لا مزوّد/نوع غير مدعوم).
     *
     * @return array{related: bool, doc_type: string, summary: string, reason: string}|null
     */
    public function analyzeDocument(Ticket $ticket, TicketDocument $doc): ?array
    {
        $abs = Storage::disk('local')->path($doc->path);
        if (! is_file($abs) || $doc->size > 8 * 1024 * 1024) {
            return null; // ملف مفقود أو أكبر من حد الفحص
        }

        $required = implode('، ', ServiceDocs::for($ticket->type));
        $subject = AiContextBuilder::prepare((string) $ticket->messages()->where('who', 'client')->first()?->body) ?: $ticket->type;

        $prevDocs = $ticket->documents()->where('id', '!=', $doc->id)->get();
        $prevDocsInfo = '';
        if ($prevDocs->isNotEmpty()) {
            $prevDocsInfo = "\nالمرفقات السابقة المرفوعة:\n".$prevDocs->map(fn ($d) => "- اسم الملف: {$d->name} | النوع: {$d->doc_type} | الملخص: {$d->summary} | الحالة: {$d->status}")->implode("\n");
        }

        $system = AiPromptRegistry::documentAnalyzeSystem();
        $context = "نوع التذكرة: {$ticket->type}\nالقسم: {$ticket->department}\nموضوع العميل: {$subject}\nالمستندات المطلوبة عادةً لهذا النوع: {$required}\nاسم الملف: {$doc->name}{$prevDocsInfo}";

        try {
            $ext = strtolower(pathinfo($doc->name, PATHINFO_EXTENSION));
            $json = null;

            if ($text = $this->extractText($abs, $ext)) {
                // محتوى نصي مستخرج — يمر عبر سلسلة المزوّدين المعتادة
                $prompt = $context."\n\nمحتوى المستند:\n".mb_substr($text, 0, 20000)."\n\nافحص المحتوى وأعد JSON.";
                $json = $this->run($system, [['role' => 'user', 'content' => $prompt]], json: true, promptId: 'document.analyze');
            } elseif (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true) && ! empty(config('services.gemini.key'))) {
                // ملف ثنائي — فحص متعدد الوسائط عبر Gemini
                $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext];
                $json = $this->viaGeminiDocument($system, $context."\n\nافحص المستند المرفق وأعد JSON.", base64_encode((string) file_get_contents($abs)), $mime);
            }

            if ($json && ($data = self::parseJsonResponse($json))) {
                if (array_key_exists('related', $data)) {
                    return [
                        'related' => (bool) $data['related'],
                        'doc_type' => (string) ($data['doc_type'] ?? 'مستند'),
                        'summary' => (string) ($data['summary'] ?? ''),
                        'reason' => (string) ($data['reason'] ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService analyzeDocument failed: '.$e->getMessage());
        }

        // عند تعذّر الـAI: لا تحليل مُختلَق — نُرجع null فيتولّى المسار الحالي وسم المستند «بحاجة لمراجعة يدوية»
        // (لا نزعم أنّ مستنداً لم يُقرأ «مرتبط + تم التحقق»).
        return null;
    }

    /**
     * تحليل مستند ملف القضية — تلخيص وتصنيف (لا حكم «صلة»: المستند دليلٌ مقبول ضمن الملف).
     * يعيد {doc_type, summary} أو null عند تعذّر القراءة/غياب المزوّد (فيُوسَم للمراجعة اليدوية).
     *
     * @return array{doc_type:string,summary:string}|null
     */
    public function analyzeCaseDocument(LegalCase $case, CaseDocument $doc): ?array
    {
        $abs = Storage::disk('local')->path($doc->path);
        if (! is_file($abs) || $doc->size > 8 * 1024 * 1024) {
            return null; // ملف مفقود أو أكبر من حد الفحص
        }

        $system = 'أنت مساعد قانوني في «النظام الإداري لمكاتب المحاماة» بالسعودية. اقرأ محتوى المستند المرفق بملف القضية كاملاً، '
            .'صنّف نوعه ولخّص محتواه بإيجاز مفيد للمحامي. أعد JSON فقط: '
            .'{"doc_type":"نوع المستند كما فهمته من محتواه","summary":"ملخّص محتوى المستند في سطر أو سطرين"}. لا نص خارج JSON.';
        $context = "نوع القضية: {$case->type}\nالقسم: ".($case->department ?? '—')."\nاسم الملف: {$doc->name}";

        try {
            $ext = strtolower(pathinfo($doc->name, PATHINFO_EXTENSION));
            $json = null;

            if ($text = $this->extractText($abs, $ext)) {
                $prompt = $context."\n\nمحتوى المستند:\n".mb_substr($text, 0, 20000)."\n\nلخّص المحتوى وصنّفه وأعد JSON.";
                $json = $this->run($system, [['role' => 'user', 'content' => $prompt]], json: true, promptId: 'document.analyze');
            } elseif (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true) && ! empty(config('services.gemini.key'))) {
                $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext];
                $json = $this->viaGeminiDocument($system, $context."\n\nلخّص المستند المرفق وصنّفه وأعد JSON.", base64_encode((string) file_get_contents($abs)), $mime);
            }

            if ($json && ($data = self::parseJsonResponse($json)) && (isset($data['summary']) || isset($data['doc_type']))) {
                return [
                    'doc_type' => (string) ($data['doc_type'] ?? 'مستند'),
                    'summary' => (string) ($data['summary'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService analyzeCaseDocument failed: '.$e->getMessage());
        }

        return null;
    }

    /** استخراج نص المستندات النصية وdocx (بلا اعتماد على مكتبات خارجية). */
    private function extractText(string $abs, string $ext): ?string
    {
        if (in_array($ext, ['txt', 'md', 'csv'], true)) {
            $text = trim((string) file_get_contents($abs));

            return $text !== '' ? $text : null;
        }

        if ($ext === 'docx' && class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive;
            if ($zip->open($abs) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                if ($xml !== false) {
                    // فواصل الفقرات ثم إزالة الوسوم
                    $text = trim(strip_tags(preg_replace('/<\/w:p>/', "\n", $xml)));

                    return $text !== '' ? $text : null;
                }
            }
        }

        return null;
    }

    /**
     * يصيغ مسودة «لائحة دعوى» للقضية (يطابق cfStatement) — ذكاء اصطناعي مع احتياط قالبي.
     */
    public function draftPleading(LegalCase $case): string
    {
        // سياق القضية الحقيقي (لا اختلاق): وقائع الملخّص المعتمد + بيانات الخصم + ملخّصات مستندات الملف
        $ticket = $case->ticket;
        $summary = $ticket?->summary;

        $facts = [];
        if ($summary) {
            foreach (['case_summary' => 'ملخّص القضية', 'facts' => 'الوقائع', 'key_points' => 'النقاط الجوهرية', 'attachments_summary' => 'ملخّص المرفقات'] as $field => $label) {
                $v = trim((string) ($summary->$field ?? ''));
                if ($v !== '') {
                    $facts[] = "{$label}: {$v}";
                }
            }
        }

        $parties = [];
        if ($ticket) {
            if ($ticket->opponent_name) {
                $parties[] = 'المدّعى عليه: '.$ticket->opponent_name.($ticket->opponent_id ? ' (هوية/سجل: '.$ticket->opponent_id.')' : '');
            }
            if ($ticket->claim_amount) {
                $parties[] = 'قيمة المطالبة: '.number_format((int) $ticket->claim_amount).' ريال';
            }
            if ($ticket->court_name) {
                $parties[] = 'المحكمة: '.$ticket->court_name;
            }
        }

        $docs = $case->documents()->whereNotNull('summary')->where('summary', '!=', '')->get()
            ->map(fn ($d) => '- '.($d->doc_type ?: 'مستند').': '.$d->summary)->implode("\n");

        $context = "رقم القضية: {$case->number}\nنوع القضية: {$case->type}\nالقسم: ".($case->department ?? '—');
        if ($parties !== []) {
            $context .= "\n".implode("\n", $parties);
        }
        if ($facts !== []) {
            $context .= "\n\nوقائع وبيانات الملف (المعتمدة):\n".implode("\n", $facts);
        }
        if ($docs !== '') {
            $context .= "\n\nملخّصات مستندات الملف:\n".$docs;
        }

        // استرجاع قانونيّ موثَّق — يُضيف ولا يَحجب: قاعدة فارغة ⇒ المسار كما هو تماماً،
        // فلا تتوقّف مسودّة عاملة اليوم بسبب ميزة لم يُغذَّ محتواها بعد. ووجود مصادر
        // يجعل الاستشهاد مطلوباً وقابلاً للمطابقة خادمياً.
        $sources = LegalKnowledge::retrieve(
            domain: (string) ($case->department ?: $case->type),
            query: (string) $case->type,
        );
        $authority = LegalKnowledge::asContext($sources);
        if ($authority !== '') {
            $context .= "\n\nمصادر نظاميّة معتمدة (استشهد بمعرّفاتها حصراً، ولا تذكر مادّة خارجها):\n".$authority;
        }

        $system = AiPromptRegistry::casePleadingSystem();
        $prompt = $context."\n\nاكتب مسودة لائحة الدعوى بناءً على ما سبق فقط.";

        try {
            $text = $this->run($system, [['role' => 'user', 'content' => $prompt]], promptId: 'case.pleading');
            if ($text && trim($text) !== '') {
                return trim($text);
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService draftPleading failed: '.$e->getMessage());
        }

        // عند تعذّر الـAI: لا لائحة مُختلَقة — رسالة أمينة تدفع للتحرير اليدوي
        return "مسودّة لائحة دعوى — {$case->number}\n\n"
            .'تعذّر توليد المسودّة بالذكاء الاصطناعي حالياً. يُرجى إعادة المحاولة لاحقاً، '
            .'أو تحرير لائحة الدعوى يدوياً (الوقائع ثم الأسانيد النظامية ثم الطلبات) بحسب مستندات القضية.';
    }

    /** قالب ملخص احتياطي عند تعذّر الذكاء الاصطناعي. */
    public function fallbackSummary(Ticket $ticket): array
    {
        return [
            'case_summary' => "طلب من العميل بخصوص «{$ticket->type}» ضمن {$ticket->department}. أحيل للقسم المختص لدراسة الموضوع وإبداء الرأي القانوني.",
            'attachments_summary' => $ticket->attachments > 0
                ? "أرفق العميل {$ticket->attachments} مستند/مستندات لدعم الطلب، بانتظار مراجعتها وتقييم دلالتها."
                : 'لم تُرفق مستندات بعد؛ يُنصح بطلب المستندات المؤيّدة قبل إبداء الرأي.',
            'facts' => "• تقدّم العميل بطلب من نوع «{$ticket->type}».\n• أحيل الطلب إلى {$ticket->department} للدراسة.\n• بانتظار الرأي القانوني من المستشار.",
            'key_points' => "• تحديد الأساس النظامي للمطالبة.\n• تقييم كفاية المستندات للإثبات.\n• اقتراح الإجراء الأنسب (إنذار/مطالبة/دعوى) بعد المراجعة.",
        ];
    }

    /**
     * يولّد ردّ الدعم الفني على آخر رسالة من العميل ضمن التذكرة.
     * يجرّب Gemini أولاً، ثم GLM احتياطياً.
     * يعيد نصّ الردّ، أو null عند تعذّر الاتصال (ليستخدم المُستدعي ردّاً احتياطياً).
     */
    public function reply(Ticket $ticket, string $clientMessage): ?string
    {
        $system = AiPromptRegistry::chatReplySystem()."\n\n".$this->context($ticket);

        return $this->run($system, $this->history($ticket, $clientMessage));
    }

    /**
     * رسالة ترحيب واحدة عند فتح التذكرة — بنبرة موظف بشري: تُرحّب، تعيد صياغة الطلب،
     * وتطلب المستندات المذكورة بأسمائها في رسالة واحدة. احتياط إنساني عند تعذّر AI.
     */
    public function greet(Ticket $ticket, string $details, array $docs): string
    {
        $docList = implode('، ', $docs);
        $system = AiPromptRegistry::chatReplySystem()."\n\n"
            .'مهمتك الآن: هذه أول رسالة للعميل بعد فتح تذكرته. رحّب به باسم المكتب بإيجاز وودّ، '
            .'وأظهر أنك فهمت طلبه بإعادة صياغة موجزة له، ثم اطلب منه بلطف إرفاق المستندات التالية لبدء الدراسة: '
            ."«{$docList}». رسالة واحدة قصيرة بنبرة إنسانية طبيعية، دون ذكر قوائم المستندات كتعداد آلي جاف ودون سرد خطوات داخلية.";
        $prompt = "نوع الطلب: {$ticket->type}\nما كتبه العميل:\n{$details}";

        try {
            $text = $this->run($system, [['role' => 'user', 'content' => $prompt]]);
            if ($text && trim($text) !== '') {
                return trim($text);
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService greet failed: '.$e->getMessage());
        }

        // احتياط إنساني (رسالة واحدة) عند تعذّر AI
        return "أهلاً بك، وصلني طلبك بخصوص «{$ticket->type}» وسأتابعه معك بإذن الله. "
            .'لأتمكّن من دراسته على الوجه الأمثل، أرجو إرفاق المستندات التالية: '.$docList.'.';
    }

    /**
     * رسالة إقرار عند فتح تذكرة مصحوبة بمستندات ذات صلة — بنبرة الموظف المختص:
     * تُرحّب، تعيد صياغة الطلب بإيجاز، وتُقرّ باستلام المستندات وأنها روجعت وستُحال للقسم المختص.
     * رسالة واحدة قصيرة. احتياط إنساني حتمي عند تعذّر AI.
     *
     * @param  array<int,array{name:string,summary:string}>  $relatedDocs
     */
    public function acknowledgeDocs(Ticket $ticket, string $details, array $relatedDocs): string
    {
        $names = array_map(fn ($d) => $d['name'], $relatedDocs);
        $nameList = implode('، ', $names);
        $docLines = implode("\n", array_map(
            fn ($d) => '- '.$d['name'].($d['summary'] !== '' ? ': '.$d['summary'] : ''),
            $relatedDocs
        ));

        $system = AiPromptRegistry::chatReplySystem()."\n\n"
            .'مهمتك الآن: العميل فتح تذكرته وأرفق مستندات ذات صلة بموضوعه فُحصت فعلاً. '
            .'رحّب به باسم المكتب بإيجاز، وأظهر أنك فهمت طلبه بإعادة صياغة موجزة، '
            .'ثم أقرّ باستلام مستنداته المرفقة وأنه تمّت مراجعتها، وطمئنه أن طلبه يُحال الآن إلى القسم المختص لدراسته. '
            .'رسالة واحدة قصيرة بنبرة إنسانية طبيعية، دون تعداد آلي جاف ودون ذكر خطوات داخلية.';
        $prompt = "نوع الطلب: {$ticket->type}\nما كتبه العميل:\n{$details}\nالمستندات المرفقة ذات الصلة:\n{$docLines}";

        try {
            $text = $this->run($system, [['role' => 'user', 'content' => $prompt]]);
            if ($text && trim($text) !== '') {
                return trim($text);
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService acknowledgeDocs failed: '.$e->getMessage());
        }

        // احتياط إنساني (رسالة واحدة) عند تعذّر AI
        return "شكراً لك، وصلني طلبك بخصوص «{$ticket->type}» ومستنداتك المرفقة ("
            .$nameList.') واطّلعنا عليها. سنحيل طلبك الآن إلى القسم المختص لدراسته وموافاتك بالمستجدات.';
    }

    /**
     * يولّد ردّ الفريق القانوني على آخر رسالة من العميل ضمن القضية (الذكاء الاصطناعي هو الأساس).
     * يعيد نصّ الردّ، أو null عند تعذّر الاتصال (ليستخدم المُستدعي ردّاً احتياطياً).
     */
    public function caseReply(LegalCase $case, string $clientMessage): ?string
    {
        $nextLabel = $case->nextHearingLabel();
        $next = $nextLabel !== '—' ? "؛ الجلسة القادمة: {$nextLabel}" : '';
        $system = AiPromptRegistry::chatReplySystem()."\n\n"
            ."سياق القضية — رقم: {$case->number}؛ النوع: {$case->type}؛ القسم: {$case->department}؛ الحالة: {$case->status}{$next}. "
            .'أنت تتابع قضية قانونية نشطة لهذا العميل؛ أجب عن استفساراته حول سير القضية والجلسات والإجراءات بدقّة وطمأنة.';

        return $this->run($system, $this->history($case, $clientMessage));
    }

    /**
     * يولّد ملخص جلسة الاستشارة بعد إنهائها (يطابق cRunAIFromSession) — ذكاء اصطناعي مع احتياط قالبي.
     * يعتمد على ملاحظات المستشار المدوّنة أثناء الجلسة إن وُجدت.
     */
    public function consultSummary(Consult $consult, string $notes = ''): string
    {
        $system = AiPromptRegistry::consultSummarySystem();
        $prompt = "استشارة {$consult->channel} رقم {$consult->ref} بموضوع «{$consult->subject}» مع المستشار {$consult->lawyer}."
            .($notes !== '' ? "\nملاحظات المستشار أثناء الجلسة:\n{$notes}" : '')
            ."\nاكتب ملخص الاستشارة.";

        try {
            $text = $this->run($system, [['role' => 'user', 'content' => $prompt]]);
            if ($text && trim($text) !== '') {
                return trim($text);
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService consultSummary failed: '.$e->getMessage());
        }

        // عند تعذّر الـAI: لا رأي قانوني مُختلَق يصل للعميل — نصّ أمين بانتظار الإعداد اليدوي
        return "ملخص استشارة — {$consult->ref}\n\n"
            ."تعذّر إعداد ملخّص الاستشارة بالذكاء الاصطناعي حالياً. الاستشارة بشأن «{$consult->subject}» "
            .'بحاجة إلى إعداد الملخّص والرأي القانوني يدوياً من الفريق القانوني قبل اعتماده'
            .($notes !== '' ? "، وقد دوّن المستشار أثناء الجلسة: {$notes}" : '').'.';
    }

    /**
     * مخرجات الاجتماع بعد إنهائه: ملخص + محضر + قرارات قابلة للتنفيذ — ذكاء اصطناعي مع احتياط قالبي.
     *
     * @return array{summary: string, minutes: string, decisions: array<int, string>}
     */
    public function meetingSummary(Meeting $meeting, string $notes = ''): array
    {
        // مصدر المحتوى الفعلي الوحيد: الملاحظات المدوَّنة و/أو ملخص Zoom إن سبق وصوله.
        // بلا أيّهما لا يُنادى الذكاء ولا يُكتب أي نصّ: التلخيص من البيانات الوصفية كان يختلق
        // مداولات وقرارات لاجتماع لم يدخله أحد وتُعرض للعميل كمحضر رسمي (حادثة M-26753)،
        // وقرار صاحب المنتج: لا قالب وهمي — الحقول تبقى فارغة حتى يصل ملخص Zoom أو يُدوَّن يدوياً.
        $zoomContent = trim((string) $meeting->zoom_summary);
        if ($notes === '' && $zoomContent === '') {
            return self::meetingSummaryFallback($meeting, $notes);
        }

        $system = AiPromptRegistry::meetingSummarySystem();
        $prompt = "عنوان الاجتماع: {$meeting->title}\nالنوع: {$meeting->type}\nالعميل: ".($meeting->client_name ?: 'داخلي')
            .($meeting->case_ref ? "\nمرتبط بـ: {$meeting->case_ref}" : '')
            .($meeting->participants ? "\nالمشاركون: {$meeting->participants}" : '')
            .($notes !== '' ? "\nملاحظات أثناء الاجتماع:\n{$notes}" : '')
            .($zoomContent !== '' ? "\nملخص جلسة Zoom:\n{$zoomContent}" : '')
            ."\nأعد JSON.";

        try {
            $json = $this->run($system, [['role' => 'user', 'content' => $prompt]], json: true);
            if ($json) {
                $data = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $json)), true);
                if (is_array($data) && ! empty($data['summary'])) {
                    return [
                        'summary' => (string) $data['summary'],
                        'minutes' => is_array($data['minutes'] ?? null) ? implode("\n", array_map('strval', $data['minutes'])) : (string) ($data['minutes'] ?? ''),
                        'decisions' => array_values(array_filter(array_map('strval', (array) ($data['decisions'] ?? [])))),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService meetingSummary failed: '.$e->getMessage());
        }

        return self::meetingSummaryFallback($meeting, $notes);
    }

    /**
     * الصياغة الأمينة — **لا قالب وهمي**: بلا محتوى فعلي تُعاد nullات فلا يُكتب شيء
     * (الحقول تبقى فارغة حتى ملخص Zoom أو التدوين اليدوي)؛ ومع ملاحظات مدوَّنة تُحفظ
     * الملاحظات نفسها موسومة كما هي — محتوى حقيقي لا اختلاق. القرارات فارغة دائماً هنا.
     */
    private static function meetingSummaryFallback(Meeting $meeting, string $notes): array
    {
        return [
            'summary' => $notes !== '' ? "ملاحظات مدوَّنة أثناء اجتماع «{$meeting->title}»:\n{$notes}" : null,
            'minutes' => null,
            'decisions' => [],
        ];
    }

    /**
     * استخراج قرارات/مهام قابلة للتنفيذ من نصّ (ملخص استشارة/اجتماع) — ذكاء اصطناعي مع احتياط قالبي.
     *
     * @return array<int, string>
     */
    public function extractDecisions(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        // التعليمة في السجلّ لا هنا: كانت الوحيدة الباقية بلا إصدار ولا بصمة
        $system = AiPromptRegistry::decisionsSystem();
        try {
            $json = $this->run($system, [['role' => 'user', 'content' => mb_substr($text, 0, 6000)]], json: true, promptId: 'meeting.decisions');
            if ($json) {
                $data = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $json)), true);
                if (is_array($data) && ! empty($data['decisions'])) {
                    return array_values(array_filter(array_map('strval', (array) $data['decisions'])));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService extractDecisions failed: '.$e->getMessage());
        }

        // عند تعذّر الـAI: لا مهام أفضل من مهام وهمية — كان الاحتياط يحقن 3 مهام ثابتة كسجلّات حقيقية
        return [];
    }

    /**
     * تحليل الفريق القانوني للاستشارة (يطابق cRunAI) — تصنيف + ملخص قانوني + محامٍ مقترح.
     * يعيد [class, summary, lawyer, missing[]] بذكاء اصطناعي مع احتياط قالبي.
     */
    public function analyzeConsult(Consult $consult): array
    {
        // محامون حقيقيون من قاعدة البيانات (لا أسماء مُختلَقة)
        $lawyers = User::where('role', Role::Lawyer)->orderBy('name')->pluck('name')->all();
        $system = AiPromptRegistry::consultAnalyzeSystem($lawyers);
        $prompt = "استشارة {$consult->ref} — الموضوع: «{$consult->subject}»، النوع: {$consult->type}، "
            ."القناة: {$consult->channel}، الأولوية: {$consult->priority}. حلّل وأعد JSON.";

        $call = new AiCallResult(text: null, traceId: (string) Str::uuid(), durationMs: 0, failureCode: AiFailure::PROVIDER_ERROR);
        $structureFailure = null;

        try {
            $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], true, promptId: 'consult.analyze');
            if ($call->succeeded()) {
                $data = self::parseJsonResponse($call->text);
                $structureFailure = $data === null ? AiFailure::INVALID_JSON : AiFailure::INVALID_STRUCTURE;
                // المحقِّق يُسقِط أي اسم خارج القائمة الحقيقيّة ولا يستبدله بأوّل محامٍ
                $valid = AiOutputValidator::consultAnalysis($data, $lawyers);
                if ($valid !== null) {
                    return $valid + [
                        'source' => AiSource::AiSuccess->value,
                        'meta' => self::callMeta($call, 'consult.analyze', null, AiConfidence::forConsult(
                            class: $valid['class'],
                            summary: $valid['summary'],
                            lawyer: $valid['lawyer'],
                            roster: $lawyers,
                            hasSubject: trim((string) $consult->subject) !== '',
                        )),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService analyzeConsult failed: '.$e->getMessage());
        }

        // عند تعذّر الـAI: لا رأي قانوني مُختلَق — حالة أمينة بحاجة مراجعة يدوية.
        // كان التعليق يقول «لا محامٍ ثابت» بينما الشيفرة تعيد $lawyers[0]: أوّل محامٍ
        // أبجديّاً يُعرض على الموظّف كـ«محامٍ مقترح» بلا أي تحليل خلفه. لا اقتراح الآن.
        return [
            'class' => "استشارة {$consult->type}",
            'summary' => "تعذّر إعداد التحليل الذكي حالياً. الاستشارة بشأن «{$consult->subject}» "
                .'بحاجة إلى مراجعة وإعداد الرأي القانوني يدوياً من الفريق القانوني قبل اعتمادها.',
            'lawyer' => '',
            'missing' => [],
            'source' => AiSource::Fallback->value,
            'meta' => self::callMeta($call, 'consult.analyze', $structureFailure),
        ];
    }

    /**
     * التحليل الذكيّ لطلب التنفيذ (تدفّق البطاقات): ملخّص قانونيّ + نواقص + إجراءات مقترحة.
     * يُعيد JSON من الـAI؛ وعند تعذّره يسقط لقالب أمين (فحص المنفَّذ ضده + إجراءات افتراضيّة).
     *
     * @return array{summary:string,missing:array<int,string>,procedures:array<int,string>,source:string}
     */
    public function analyzeExecution(Execution $exec): array
    {
        $defaultProcs = ['تقديم طلب تنفيذ إلكتروني', 'طلب الإفصاح عن الأصول', 'الحجز على الحسابات'];

        // قراءة وتلخيص كافة المستندات المرفقة مع الطلب
        $exec->loadMissing('documents');
        $docSnippets = [];
        $readableDocs = 0; // ما أمكن استخراج محتواه فعلاً — إشارة ثقة موضوعيّة
        foreach ($exec->documents as $doc) {
            $abs = Storage::disk('local')->path((string) $doc->path);
            $fileName = basename((string) $doc->path);
            $docContent = '';
            if (is_file($abs)) {
                $ext = strtolower(pathinfo((string) $doc->path, PATHINFO_EXTENSION));
                if ($text = $this->extractText($abs, $ext)) {
                    $docContent = AiContextBuilder::prepare($text, 3000);
                } elseif (! empty($doc->summary)) {
                    $docContent = $doc->summary;
                }
            } elseif (! empty($doc->summary)) {
                $docContent = $doc->summary;
            }
            $readableDocs += $docContent !== '' ? 1 : 0;
            $docSnippets[] = "- ملف: {$fileName} (التصنيف: {$doc->label})".($docContent ? " — محتواه:\n{$docContent}" : '');
        }

        $docsContext = ! empty($docSnippets)
            ? "\n\nالمستندات المرفقة مع الطلب:\n".implode("\n", $docSnippets)
            : "\n(لم يتم إرفاق مستندات بعد)";

        $system = AiPromptRegistry::executionAnalyzeSystem();
        $prompt = "طلب تنفيذ {$exec->number} — نوع السند: {$exec->sanad}، الموضوع: «{$exec->subject}»، "
            .'قيمة المطالبة: '.number_format((int) $exec->amount).' ريال، '
            .'المنفَّذ ضده: '.($exec->defendant ?: 'غير محدّد').'، '
            .'ملاحظات: '.($exec->notes ?: '—').'.'
            .$docsContext
            ."\n\nحلّل الطلب والمستندات المرفقة وأعد JSON.";

        $call = new AiCallResult(text: null, traceId: (string) Str::uuid(), durationMs: 0, failureCode: AiFailure::PROVIDER_ERROR);
        $structureFailure = null;

        try {
            $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], true, promptId: 'execution.analyze');
            if ($call->succeeded()) {
                // التحقّق الخادميّ المستقلّ: لا يُوثَق بأن النموذج اتّبع التعليمات
                $data = self::parseJsonResponse($call->text);
                $structureFailure = $data === null ? AiFailure::INVALID_JSON : AiFailure::INVALID_STRUCTURE;
                $valid = AiOutputValidator::executionAnalysis($data, $defaultProcs);
                if ($valid !== null) {
                    return $valid + [
                        'source' => AiSource::AiSuccess->value,
                        'meta' => self::callMeta($call, 'execution.analyze', null, AiConfidence::forExecution(
                            summary: $valid['summary'],
                            documentsTotal: $exec->documents->count(),
                            documentsReadable: $readableDocs,
                            hasDefendant: trim((string) $exec->defendant) !== '',
                            hasSanad: trim((string) $exec->sanad) !== '',
                            hasAmount: (int) $exec->amount > 0,
                        )),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService analyzeExecution failed: '.$e->getMessage());
        }

        // fallback أمين (نفس منطق القالب السابق) — لا يفشل التحليل أبداً.
        // ⚠️ هذا قالب حتميّ لا يقرأ مستنداً واحداً: يفحص وجود اسم المنفَّذ ضده فقط.
        // `source` إلزاميّ هنا — بدونه كان المستهلك يضبط ai_done=true ويُشعر العميل
        // بأن «الذكاء الاصطناعي حلّل الطلب» وهو لم يُستدعَ أصلاً.
        $missing = trim((string) $exec->defendant) === '' ? ['بيانات المنفَّذ ضده'] : [];
        $complete = count($missing) === 0;
        $summary = 'سند تنفيذي من نوع '.$exec->sanad.' بقيمة '.number_format((int) $exec->amount).' ريال'
            .($exec->defendant ? ' بحق '.$exec->defendant : '').'، '
            .($complete ? 'مؤهّل للإحالة إلى قسم التنفيذ ومباشرة الإجراءات' : 'ويلزم استكمال النواقص قبل الإحالة').'.';

        return [
            'summary' => $summary,
            'missing' => $missing,
            'procedures' => $defaultProcs,
            'source' => AiSource::Fallback->value,
            'meta' => self::callMeta($call, 'execution.analyze', $structureFailure),
        ];
    }

    /**
     * تحليل مستند مرفَق على طلب تنفيذ (نظير analyzeCaseDocument، بسياق التنفيذ: نوع السند/قيمة
     * المطالبة/المنفَّذ ضده) — يُقيّم مدى توافق محتوى المستند مع بيانات الطلب لا تصنيفاً عاماً فقط.
     *
     * @return array{doc_type:string,summary:string}|null
     */
    public function analyzeExecutionDocument(Execution $exec, ExecutionDocument $doc): ?array
    {
        $abs = Storage::disk('local')->path((string) $doc->path);
        if (! is_file($abs) || (int) $doc->size > 8 * 1024 * 1024) {
            return null; // ملف مفقود أو أكبر من حد الفحص
        }

        $fileName = basename((string) $doc->path);
        $system = 'أنت مساعد قانوني في قسم التنفيذ في «النظام الإداري لمكاتب المحاماة» بالسعودية. اقرأ محتوى المستند المرفق على طلب تنفيذ كاملاً، '
            .'صنّف نوعه، ولخّص محتواه موضحاً مدى توافقه مع بيانات الطلب (نوع السند/قيمة المطالبة/المنفَّذ ضده) إن أمكن. أعد JSON فقط: '
            .'{"doc_type":"نوع المستند كما فهمته من محتواه","summary":"ملخّص محتوى المستند وتوافقه مع الطلب في سطر أو سطرين"}. لا نص خارج JSON.';
        $context = "طلب تنفيذ {$exec->number} — نوع السند: {$exec->sanad}، الموضوع: «{$exec->subject}»، "
            .'قيمة المطالبة: '.number_format((int) $exec->amount).' ريال، '
            .'المنفَّذ ضده: '.($exec->defendant ?: 'غير محدّد')."\nاسم الملف: {$fileName}";

        try {
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $json = null;

            if ($text = $this->extractText($abs, $ext)) {
                $prompt = $context."\n\nمحتوى المستند:\n".mb_substr($text, 0, 20000)."\n\nلخّص المحتوى وصنّفه وأعد JSON.";
                $json = $this->run($system, [['role' => 'user', 'content' => $prompt]], json: true);
            } elseif (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true) && ! empty(config('services.gemini.key'))) {
                $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext];
                $json = $this->viaGeminiDocument($system, $context."\n\nلخّص المستند المرفق وصنّفه وأعد JSON.", base64_encode((string) file_get_contents($abs)), $mime);
            }

            if ($json && ($data = self::parseJsonResponse($json)) && (isset($data['summary']) || isset($data['doc_type']))) {
                return [
                    'doc_type' => (string) ($data['doc_type'] ?? 'مستند'),
                    'summary' => (string) ($data['summary'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService analyzeExecutionDocument failed: '.$e->getMessage());
        }

        return null;
    }

    /**
     * إسناد المحامي المختص للتذكرة (الذكاء الاصطناعي هو المُسنِد الأول): يختار الأنسب تخصّصاً
     * من قائمة مرشّحين جهّزها المُستدعي (مرتّبة حتمياً). يُعيد معرّف المحامي المختار، أو أول
     * المرشحين احتياطاً عند تعذّر الذكاء الاصطناعي — فلا يفشل الإسناد أبداً ما دام هناك مرشّح.
     *
     * @param  Collection<int, User>  $candidates
     */
    /** ⚠️ غير مستعملة حالياً: TicketAssignment استُبدلت بترتيب حتميّ سريع (كانت تعلّق الطلب حتى 150ث
     *  عبر WebTimeLimit::raise، وقيمتها صفر لأن المسبح مُرشَّح بالتخصّص سلفاً). محفوظة للرجوع. */
    public function chooseLawyer(Ticket $ticket, Collection $candidates): ?int
    {
        if ($candidates->isEmpty()) {
            return null;
        }
        $default = (int) $candidates->first()->id;
        $ids = $candidates->pluck('id')->map(fn ($i) => (int) $i)->all();

        $list = $candidates->map(fn ($u) => "- id={$u->id} | {$u->name} | التخصّص: ".($u->department ?: '—'))->implode("\n");
        $system = 'أنت منسّق إسناد في «النظام الإداري لمكاتب المحاماة». اختر المحامي الأنسب تخصّصاً لموضوع التذكرة من القائمة. '
            .'أعد JSON فقط: {"lawyer_id": المعرّف الرقمي للمحامي المختار}. لا نص خارج JSON.';
        $prompt = "نوع التذكرة: {$ticket->type}\nالقسم: ".($ticket->department ?: '—')."\n\nالمحامون المتاحون:\n{$list}";

        try {
            $json = $this->run($system, [['role' => 'user', 'content' => $prompt]], json: true);
            if ($json) {
                $data = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $json)), true);
                $id = (int) ($data['lawyer_id'] ?? 0);
                if (in_array($id, $ids, true)) {
                    return $id;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService chooseLawyer failed: '.$e->getMessage());
        }

        return $default;
    }

    /**
     * ترتيب المحامين المتخصّصين بأولوية الذكاء الاصطناعي لعرضهم على العميل عند حجز الاستشارة.
     * المرشّحون مُرتّبون حتمياً مسبقاً (الأكثر إنجازاً أولاً)؛ يعيد الذكاء ترتيبهم حسب الأنسب،
     * وعند التعذّر يعود الترتيب الحتميّ كما هو — فلا يفشل أبداً.
     *
     * @param  array<int, array{id:int,name:string,dept:string,success:array,load:int}>  $candidates
     * @return array<int, int> معرّفات المحامين مرتّبة بالأولوية
     */
    /** ⚠️ غير مستعملة حالياً: LawyerAvailability استُبدلت بترتيب حتميّ سريع (كانت تعلّق الطلب حتى 150ث). محفوظة للرجوع. */
    public function rankLawyers(string $specialty, string $subject, array $candidates): array
    {
        $ids = array_map(fn ($c) => (int) $c['id'], $candidates);
        if (count($ids) <= 1) {
            return $ids;
        }

        $list = collect($candidates)->map(function ($c) {
            $s = $c['success'] ?? [];

            return "- id={$c['id']} | {$c['name']} | التخصّص: ".($c['dept'] ?? '—')
                .' | معدّل الإنجاز: '.($s['rate'] ?? 0).'% ('.($s['closed'] ?? 0).'/'.($s['total'] ?? 0).')'
                .' | الحمل المفتوح: '.($c['load'] ?? 0);
        })->implode("\n");

        $system = 'أنت منسّق إسناد في «النظام الإداري لمكاتب المحاماة». رتّب المحامين حسب الأنسب لاستشارة العميل: '
            .'الأعلى تخصّصاً وسجلّ نجاح أولاً ثم الأقل حملاً. '
            .'أعد JSON فقط: {"order":[معرّفات المحامين مرتّبة من الأنسب]}. أدرِج كل المعرّفات مرة واحدة فقط. لا نص خارج JSON.';
        $prompt = "تخصّص الاستشارة: {$specialty}\nموضوع الاستشارة: {$subject}\n\nالمحامون المتاحون:\n{$list}";

        try {
            $json = $this->run($system, [['role' => 'user', 'content' => $prompt]], json: true);
            if ($json) {
                $data = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $json)), true);
                $order = array_map(fn ($i) => (int) $i, (array) ($data['order'] ?? []));
                // اقبل المعرّفات المعروفة فقط، ثم ألحق أي مفقود بترتيبه الحتميّ الأصلي
                $valid = array_values(array_unique(array_filter($order, fn ($id) => in_array($id, $ids, true))));
                foreach ($ids as $id) {
                    if (! in_array($id, $valid, true)) {
                        $valid[] = $id;
                    }
                }
                if (count($valid) === count($ids)) {
                    return $valid;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService rankLawyers failed: '.$e->getMessage());
        }

        return $ids;
    }

    /**
     * المساعد القانوني الذكي للمحامي — يولد اللوائح، مذكرات الرد، فحص العقود،
     * استخراج نقاط القوة والضعف، وتكييف النزاع وفق الأنظمة السعودية.
     */
    public function assist(string $kind, string $docType, ?string $ref = null, string $context = ''): string
    {
        $refText = $ref ? "المرجع: {$ref}\n" : '';
        $refContext = '';

        if ($ref) {
            $ticket = Ticket::where('number', $ref)->with(['summary', 'documents'])->first();
            if ($ticket) {
                $refContext = "بيانات التذكرة: {$ticket->type} — {$ticket->subject}\nتفاصيل: {$ticket->details}\n";
                if ($ticket->summary) {
                    $refContext .= "ملخص الوقائع: {$ticket->summary->facts}\nالتوصيات: {$ticket->summary->key_points}\n";
                }
            }
        }

        $fullContext = trim($refText.$refContext."\nالسياق والمستندات:\n".$context);

        // المسارات الثلاثة التي تفرضها الخطة للاسترجاع المحدود: التكييف، ومسودات
        // اللوائح، وتحليل العقد. غيرها لا يُمرَّر له سند كي لا يتوسّع النطاق بلا قياس.
        if (in_array($kind, ['qualification', 'contract_check', 'analyze', 'lawahe'], true)) {
            $authority = LegalKnowledge::asContext(LegalKnowledge::retrieve(domain: '', query: $docType));
            if ($authority !== '') {
                $fullContext .= "\n\nمصادر نظاميّة معتمدة (استشهد بمعرّفاتها حصراً، ولا تذكر مادّة خارجها):\n".$authority;
            }
        }

        // خمس تعليمات تُنتقى بـ$kind — انتقلت إلى AiPromptRegistry مع تجميد بصماتها
        $system = AiPromptRegistry::assistantDraftSystem($kind, $docType);

        $prompt = "نوع المستند المطلوب: {$docType}\n\n{$fullContext}\n\nيرجى إعداد المسودة باحترافية عالية وبلفظ قانوني سعودي رصين.";

        try {
            $output = $this->run($system, [['role' => 'user', 'content' => $prompt]], json: false);
            if ($output && trim($output) !== '') {
                return trim($output);
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService assist failed: '.$e->getMessage());
        }

        // احتياط منظم وفاخر عند عدم اتصال الـ AI
        return $this->fallbackAssistantDraft($kind, $docType, $ref, $context);
    }

    /**
     * توليد مسودة صحيفة دعوى / لائحة مطابقة لمعايير منصة «ناجز».
     */
    public function generateNajizDraft(Ticket $ticket): string
    {
        $ticket->loadMissing(['user', 'summary', 'documents']);
        $clientName = $ticket->user?->name ?? 'المدعي';
        $summary = $ticket->summary;

        $context = "رقم التذكرة: {$ticket->number}\n"
            ."نوع القضية: {$ticket->type}\n"
            ."موضوع النزاع: {$ticket->subject}\n"
            ."العميل: {$clientName}\n"
            ."تفاصيل التذكرة:\n{$ticket->details}\n";

        if ($summary) {
            $context .= "\nملخص القضية:\n{$summary->case_summary}\n"
                ."الوقائع:\n{$summary->facts}\n"
                ."الأسانيد والتوصيات:\n{$summary->key_points}\n";
        }

        $system = 'أنت محامٍ مرخص ومختص في صياغة صحائف الدعوى واللوائح المعتمدة لمنصة «ناجز» بوزارة العدل السعودية. '
            .'قم بصياغة صحيفة دعوى متكاملة الأركان وفق متطلبات القضاء السعودي تتضمن: '
            .'1. بيانات المحكمة المختصة '
            .'2. بيانات المدعي والمدعى عليه '
            .'3. موضوع الدعوى '
            .'4. وقائع الدعوى (سرد زمني مرتب) '
            .'5. الأسانيد الشرعية والنظامية (مع ذكر نصوص المواد من نظام المعاملات المدنية أو نظام الإثبات أو النظام التجاري) '
            .'6. الطلبات الختامية المحددة بدقة.';

        $prompt = "بيانات ملف التذكرة:\n{$context}\n\nصغ صحيفة الدعوى لمعايير ناجز بشكل نهائي وجاهز للمراجعة.";

        try {
            $output = $this->run($system, [['role' => 'user', 'content' => $prompt]], json: false);
            if ($output && trim($output) !== '') {
                return trim($output);
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService generateNajizDraft failed: '.$e->getMessage());
        }

        // مسودة ناجز احتياطية منظمة
        return "المملكة العربية السعودية\nوزارة العدل — منصة ناجز الإلكترونية\nصحيفة دعوى إلكترونية\n\n"
            .'لدى المحكمة المختصة بمدينة: '.config('office.city')."\n\n"
            ."المدعي: {$clientName}\n"
            ."المدعى عليه: (يُحدد حسب بيانات الخصم)\n"
            ."نوع الدعوى: {$ticket->type}\n"
            ."موضوع النزاع: {$ticket->subject}\n\n"
            ."أولاً: الوقائع:\n"
            .($summary?->facts ?: $ticket->details ?: "1. بتاريخ الاتفاق بين الطرفين، التزم المدعي بكافة التزاماته التعاقدية.\n2. امتنع المدعى عليه عن الوفاء بالتزامه دون مسوغ نظامي.\n3. تم إخطار المدعى عليه ومطالبته ودياً دون جدوى.")."\n\n"
            ."ثانياً: الأسانيد النظامية:\n"
            ."- استناداً لأحكام نظام المعاملات المدنية الصادر بالمرسوم الملكي رقم (م/191) بشأن العقد شريعة المتعاقدين والمسؤولية العقدية.\n"
            ."- استناداً لأحكام نظام الإثبات الصادر بالمرسوم الملكي رقم (م/43) بشأن حجية المحررات والالتزامات.\n"
            ."- استناداً لأحكام نظام المرافعات الشرعية.\n\n"
            ."ثالثاً: الطلبات الختامية:\n"
            ."1. إلزام المدعى عليه بالوفاء بالحق والالتزام محل النزاع.\n"
            ."2. إلزام المدعى عليه بالتعويض عن الأضرار الناشئة عن المماطلة.\n"
            ."3. إلزام المدعى عليه بأتعاب المحاماة ومصاريف الدعوى.\n\n"
            ."وتفضلوا بقبول وافر الاحترام والتقدير،،\nوكيل المدعي / النظام الإداري لمكاتب المحاماة";
    }

    /** مسودة احتياطية منظمة للمساعد القانوني */
    private function fallbackAssistantDraft(string $kind, string $docType, ?string $ref, string $context): string
    {
        $refHeader = $ref ? "المرجع القضائي: {$ref}\n\n" : '';

        return match ($kind) {
            'reply_memo', 'mems' => "بسم الله الرحمن الرحيم\n\n{$refHeader}"
                ."لدى فضيلة رئيس وأعضاء الدائرة القضائية الموقرة\n"
                ."السلام عليكم ورحمة الله وبركاته،، وبعد:\n\n"
                ."الموضوع: {$docType}\n\n"
                ."أولاً - من حيث الشكل:\n"
                ."نتمسك بتقديم هذه المذكرة في الميعاد النظامي المحدد وفقاً لأحكام نظام المرافعات ولائحته التنفيذية.\n\n"
                ."ثانياً - من حيث الموضوع:\n"
                ."رداً على ما ورد في لائحة الخصم، نوضح لعدالة الدائرة الآتي:\n"
                ."1. عدم صحة ما ادعاه الخصم، ومخالفته للواقع والمستندات الثابتة.\n"
                ."2. ثبوت التزام موكلنا بكافة الشروط المتفق عليها.\n\n"
                ."ثالثاً - الأسانيد النظامية:\n"
                ."- استناداً لنظام المعاملات المدنية (العقد شريعة المتعاقدين).\n"
                ."- استناداً لنظام الإثبات (البينة على المدعي واليمين على من أنكر).\n\n"
                ."رابعاً - الطلبات:\n"
                ."نلتمس من فضيلتكم الحكم بـ: رد دعوى المدعي وإلزامه بالمصاريف وأتعاب المحاماة.\n\n"
                .'والله يحفظكم ويرعاكم،،',

            'contract_check', 'analyze' => "تقرير تدقيق وفحص قانوني\n{$refHeader}"
                ."نوع الوثيقة: {$docType}\n\n"
                ."1. ملخص الفحص الأولي:\n"
                ."تم فحص بنود العقد ومطابقتها مع الأنظمة السارية في المملكة العربية السعودية.\n\n"
                ."2. تقييم المخاطر والثغرات:\n"
                ."• شرط الفسخ والإنهاء: يحتاج إضافة مهلة إخطار كتابي صريحة (15 يوماً).\n"
                ."• شرط التعويض والغرامات: يجب ألا يتجاوز الضرر الفعلي المباشر تفادياً لعدم إقراره قضائياً.\n"
                ."• الاختصاص القضائي: يُوصى بتحديد المحكمة المختصة بمدينة الرياض صراحةً.\n\n"
                ."3. التوصيات النهائية:\n"
                .'إعادة صياغة بند المسؤولية والضمان بما يضمن حماية حقوق الموكل قبل التوقيع النهائي.',

            'strengths_weaknesses', 'defense' => "تقرير تحليل الموقف القضائي والدفوع\n{$refHeader}"
                ."موضوع النزاع: {$docType}\n\n"
                ."أولاً - نقاط القوة (Strengths):\n"
                ."• وجود مستندات كتابية ومراسلات تثبت التعامل والالتزام (حجة نظامية وفق نظام الإثبات).\n"
                ."• سلامة الموقف التعاقدي وعدم وجود إخلال من جانب الموكل.\n\n"
                ."ثانياً - نقاط الضعف والمخاطر (Weaknesses & Risks):\n"
                ."• غياب توقيع استلام رسمي لبعض الدفعات أو الإخطارات.\n"
                ."• احتمال تمسك الخصم بمهل التقادم أو الدفع الشكلي.\n\n"
                ."ثالثاً - خطة الترافع الموصى بها:\n"
                .'تقديم مذكرة مركزة تدعمها المستندات الرقمية مع طلب إلزام الخصم بتقديم دفاتره أو فواتيره.',

            'qualification' => "مذكرة التكييف القانوني وتحديد الاختصاص\n{$refHeader}"
                ."1. التكييف النظامي للنزاع: دعوى ناشئة عن التزام عقدي تجاري / مدني.\n"
                ."2. المحكمة المختصة: المحكمة التجارية (أو العامة حسب صفة الأطراف وقيمة المطالبة).\n"
                ."3. النظام المنطبق: نظام المعاملات المدنية الصادر بالمرسوم الملكي (م/191).\n"
                .'4. الإجراء المطلوب: توجيه إنذار عدلي/كتابي ثم قيد الدعوى عبر منصة ناجز.',

            default => "مسودة قانونية: {$docType}\n\n{$refHeader}".($context ?: 'تم إعداد المسودة القانونية وفق المعايير والأنظمة السعودية المعتمدة.'),
        };
    }

    /**
     * النداء عبر البوّابة الموحَّدة — يعيد الحصيلة كاملة (نصّ + مزوّد + نموذج + زمن + تتبّع).
     * ترتيب المزوّدين وقاطع الدائرة انتقلا إلى `AiGateway` بلا تغيير في المنطق.
     */
    private function runCall(string $system, array $messages, bool $json = false, ?string $promptId = null): AiCallResult
    {
        // مسارٌ أطفأه المكتب لا يُنادى أصلاً. الإطفاء يسقط إلى الاحتياطيّ الموسوم نفسه
        // الذي يعمل عند تعذّر المزوّد — فلا مسار جديد يُختبَر، ولا يرى العميل شيئاً
        // تشغيلياً. ورمزٌ مميَّز كي لا يُقرأ الإطفاء المتعمَّد عطلاً يُنتظَر زواله.
        if ($promptId !== null && ! Setting::aiTaskEnabled($promptId)) {
            return new AiCallResult(
                text: null,
                traceId: (string) Str::uuid(),
                durationMs: 0,
                failureCode: AiFailure::TASK_DISABLED,
            );
        }

        WebTimeLimit::raise(150); // مهلة الويب (30ث) لا تكفي سلسلة المزوّدين وإعادة محاولاتها

        // بصمة الحمولة تُقاس **هنا**: آخر موضع يمرّ به النصّ قبل مغادرته الخادم.
        // قياسها عند المصدر يقول «كنّا سنموّه»؛ وقياسها هنا يقول «ما غادر يحمل هذا
        // العدد من المعرّفات المموَّهة وهذا الحجم» — وهو ما يسأل عنه المدقّق.
        $audit = AiContextBuilder::outboundAudit(
            $system."\n".implode("\n", array_column($messages, 'content'))
        );

        return app(AiGateway::class)->call(fn (string $provider) => match ($provider) {
            'gemini' => $this->viaGemini($system, $messages, $json, $promptId),
            'glm' => $this->viaGlm($system, $messages, $json, $promptId),
            default => null,
        })->withOutboundAudit($audit);
    }

    /**
     * نداء تقييميّ: يشغّل تعليمةً على نصّ **مصطنَع** ويعيد المخرج المفكوك مع كلفته.
     *
     * وجوده لأن كل دوالّ التحليل تشترط كياناً حقيقياً (تذكرة/استشارة/تنفيذ)، والتقييم
     * ممنوع أن يمسّ بيانات عملاء. لا يسجّل في `ai_runs` ولا يغيّر حالة أيّ كيان —
     * قياسٌ خارج مسار العمل لا عمليّة فيه.
     *
     * @return array{data:?array, cost:?float, model:?string, failure:?string}
     */
    public function evaluationCall(string $system, string $prompt, ?string $forceProvider = null): array
    {
        // مزوّدٌ بعينه: **بلا تراجع إلى غيره**. البوّابة تنتقل للمزوّد التالي عند
        // التعثّر، ولو فعلت هنا لَحكم على المخرج نموذجُ منتِجه صامتاً — فتسقط
        // استقلاليّة الحَكَم بلا أن يظهر ذلك في النتيجة.
        if ($forceProvider !== null) {
            $messages = [['role' => 'user', 'content' => $prompt]];
            $text = match ($forceProvider) {
                'gemini' => $this->viaGemini($system, $messages, true),
                'glm' => $this->viaGlm($system, $messages, true),
                default => null,
            };

            return [
                'data' => $text ? self::parseJsonResponse($text) : null,
                'cost' => null, // لا استهلاك مقروء خارج البوّابة — و`null` أصدق من صفر
                'model' => AiModelRouter::modelFor($forceProvider),
                'failure' => $text ? null : AiFailure::PROVIDER_ERROR,
            ];
        }

        // بلا `promptId` عمداً: التقييم يجب أن يعمل **حتى على مسارٍ مُطفأ**، وإلّا
        // استحال قياسه لإعادة تفعيله — فيبقى المطفأ مطفأً لتعذُّر إثبات صلاحه.
        $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], json: true);

        return [
            'data' => $call->succeeded() ? self::parseJsonResponse($call->text) : null,
            // `null` = سعر النموذج غير مضبوط، لا نداء مجّانيّ
            'cost' => $call->estimatedCost(),
            'model' => $call->model,
            'failure' => $call->failureCode,
        ];
    }

    /**
     * طبقة توافق: تعيد النصّ وحده كما كانت. تستعملها الدوالّ التي لا تسجّل في `ai_runs`
     * بعد؛ ومن يسجّل ينادي `runCall` ليحمل القيدَ بياناتِ تتبّع حقيقيّة لا مخمَّنة.
     */
    private function run(string $system, array $messages, bool $json = false, ?string $promptId = null): ?string
    {
        return $this->runCall($system, $messages, $json, $promptId)->text;
    }

    /**
     * بيانات التتبّع المرافقة لمخرج التحليل — تُمرَّر إلى `AiRun::record` في طبقة الأعمال.
     *
     * @return array{trace_id:string,model:?string,duration_ms:int,prompt_version:?string,failure_code:?string}
     */
    private static function callMeta(AiCallResult $call, string $promptId, ?string $failureCode = null, ?array $confidence = null): array
    {
        return [
            'trace_id' => $call->traceId,
            'model' => $call->model,
            'duration_ms' => $call->durationMs,
            'input_tokens' => $call->usage?->inputTokens,
            'output_tokens' => $call->usage?->outputTokens,
            'estimated_cost' => $call->estimatedCost(),
            // المعرّف نفسه لا نسخة ثانية منه: بوّابة السياسة تقيس الحساسيّة بدقّة
            // التعليمة، فتقرؤه من هنا بدل تكراره في كل موضع استدعاء
            'prompt_id' => $promptId,
            'prompt_version' => AiPromptRegistry::version($promptId),
            'failure_code' => $failureCode ?? $call->failureCode,
            // `null` = لا قياس (احتياطيّ/فشل)، لا «ثقة منخفضة» — الفارق جوهريّ للمراجع
            'confidence' => $confidence['score'] ?? null,
            'confidence_signals' => $confidence['signals'] ?? null,
            // دليل تقليل البيانات: كم معرّفاً مُوّه وكم بلغ حجم ما غادر الخادم
            'outbound_audit' => $call->outboundAudit,
        ];
    }

    /** الردّ عبر GLM (z.ai) — واجهة متوافقة مع OpenAI. */
    private function viaGlm(string $system, array $messages, bool $json = false, ?string $promptId = null): ?string
    {
        $msgs = array_merge(
            [['role' => 'system', 'content' => $system]],
            array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $messages),
        );

        $body = [
            'model' => AiModelRouter::modelFor('glm', $promptId),
            'messages' => $msgs,
            'max_tokens' => 2048,
            'temperature' => $json ? 0.3 : 0.7,
        ];
        if ($json) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $base = rtrim((string) config('services.glm.base', 'https://api.z.ai/api/paas/v4'), '/');
        $response = Http::timeout(30)
            ->withToken(config('services.glm.key'))
            ->retry(2, 700, fn ($e) => $e instanceof ConnectionException, throw: false)
            ->post("{$base}/chat/completions", $body);

        if ($response->failed()) {
            // الرمز لا الجسم: استجابة الخطأ قد تُعيد أجزاءً من الطلب (نصوص مستندات العملاء)
            Log::warning('GLM API error: '.$response->status().' ['.AiFailure::classify($response->status(), $response->body()).']');
            // نفاد رصيد (1113) أو تجاوز معدّل (429) → تهدئة أطول (الرصيد لا يعود قريباً)
            if ($response->status() === 429 || str_contains($response->body(), '1113')) {
                $this->cooldown('glm', 6 * 60);
            }

            return null;
        }

        app(AiGateway::class)->recordUsage(AiUsage::fromOpenAiCompatible($response->json()));

        $text = $response->json('choices.0.message.content');

        return $text ? trim($text) : null;
    }

    /** سياق التذكرة المُمرّر للنموذج. */
    private function context(Ticket $ticket): string
    {
        return "سياق التذكرة — النوع: {$ticket->type}؛ القسم: {$ticket->department}؛ الحالة: {$ticket->status}.";
    }

    /**
     * تاريخ المحادثة (آخر 12 رسالة) كأدوار user/assistant، يبدأ دائماً برسالة user.
     *
     * @param  Ticket|LegalCase  $ticket
     * @return array<int, array{role: string, content: string}>
     */
    private function history($ticket, string $clientMessage): array
    {
        $messages = [];
        foreach ($ticket->messages()->latest('id')->take(12)->get()->reverse() as $m) {
            if ($m->who === 'note') {
                continue; // الملاحظات الداخلية لا تُرسل للنموذج
            }
            $role = in_array($m->who, ['client', 'me'], true) ? 'user' : 'assistant';
            $text = AiContextBuilder::prepare((string) $m->body);
            if ($text !== '') {
                $messages[] = ['role' => $role, 'content' => $text];
            }
        }

        // يجب أن يبدأ السياق برسالة user
        while (! empty($messages) && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }
        if (empty($messages)) {
            $messages[] = ['role' => 'user', 'content' => AiContextBuilder::prepare($clientMessage)];
        }

        return $messages;
    }

    /** الردّ عبر Gemini (Google Generative Language REST API). */
    private function viaGemini(string $system, array $messages, bool $json = false, ?string $promptId = null): ?string
    {
        // الموجّه لا الثابت: يقع على النموذج المهيَّأ ما لم يُضبط تجاوزٌ للمهمّة
        $model = AiModelRouter::modelFor('gemini', $promptId);

        // Gemini يستخدم الدور "model" بدل "assistant"
        $contents = array_map(fn ($m) => [
            'role' => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ], $messages);

        $generationConfig = [
            'maxOutputTokens' => $json ? 2048 : 2048,
            'temperature' => $json ? 0.4 : 0.7,
            // تعطيل «التفكير» في نماذج 2.5 — وإلا التهم ميزانية المخرجات وعاد الردّ فارغاً
            'thinkingConfig' => ['thinkingBudget' => 0],
        ];
        if ($json) {
            $generationConfig['responseMimeType'] = 'application/json';
        }

        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => $contents,
            'generationConfig' => $generationConfig,
        ];
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        // إعادة محاولة عند الازدحام المؤقت (503/500/429) — حتى 3 محاولات بمهلة متصاعدة
        $response = Http::timeout(40)
            ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
            ->retry(3, 700, fn ($e, $req) => $e instanceof ConnectionException, throw: false)
            ->post($url, $payload);

        if (in_array($response->status(), [500, 503, 429], true)) {
            usleep(800000); // 0.8s ثم محاولة أخيرة
            $response = Http::timeout(40)
                ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                ->post($url, $payload);
        }

        if ($response->failed()) {
            Log::warning('Gemini API error: '.$response->status().' ['.AiFailure::classify($response->status(), $response->body()).']');
            // نفاد الحصّة (429/RESOURCE_EXHAUSTED) → تهدئة المزوّد فلا تُهدر النداءات
            if ($response->status() === 429 || str_contains($response->body(), 'RESOURCE_EXHAUSTED')) {
                $this->cooldown('gemini', (int) config('services.ai.cooldown', 30));
            }

            return null;
        }

        // الاستهلاك يُقرأ من ردّ المزوّد لا يُقدَّر — بدونه لا كلفة ولا ميزانية
        app(AiGateway::class)->recordUsage(AiUsage::fromGemini($response->json()));

        // قد تتعدّد الأجزاء؛ نلتقط أوّل جزء نصّي
        $parts = $response->json('candidates.0.content.parts') ?? [];
        $text = '';
        foreach ($parts as $p) {
            if (! empty($p['text'])) {
                $text .= $p['text'];
            }
        }

        return trim($text) !== '' ? trim($text) : null;
    }

    /** فحص مستند ثنائي (PDF/صورة) عبر Gemini متعدد الوسائط — يعيد JSON نصياً أو null. */
    private function viaGeminiDocument(string $system, string $prompt, string $base64, string $mime): ?string
    {
        WebTimeLimit::raise(150); // فحص الملفات الكبيرة قد يتجاوز مهلة الويب

        $model = config('services.gemini.model', 'gemini-2.5-flash');
        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['inline_data' => ['mime_type' => $mime, 'data' => $base64]],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => [
                'maxOutputTokens' => 2048,
                'temperature' => 0.3,
                'responseMimeType' => 'application/json',
                'thinkingConfig' => ['thinkingBudget' => 0],
            ],
        ];
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $response = Http::timeout(60)
            ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
            ->retry(2, 800, fn ($e) => $e instanceof ConnectionException, throw: false)
            ->post($url, $payload);

        if ($response->failed()) {
            Log::warning('Gemini document API error: '.$response->status().' ['.AiFailure::classify($response->status(), $response->body()).']');
            if ($response->status() === 429 || str_contains($response->body(), 'RESOURCE_EXHAUSTED')) {
                $this->cooldown('gemini', (int) config('services.ai.cooldown', 30));
            }

            return null;
        }

        $parts = $response->json('candidates.0.content.parts') ?? [];
        $text = '';
        foreach ($parts as $p) {
            if (! empty($p['text'])) {
                $text .= $p['text'];
            }
        }

        return trim($text) !== '' ? trim($text) : null;
    }
}
