<?php

namespace App\Services;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\CaseDocument;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\ExecutionDocument;
use App\Models\LegalCase;
use App\Models\LegalSource;
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
use App\Services\Ai\AiOpsMetrics;
use App\Services\Ai\AiOutputValidator;
use App\Services\Ai\AiPromptRegistry;
use App\Services\Ai\AiUsage;
use App\Services\Ai\LegalClaims;
use App\Services\Ai\LegalKnowledge;
use App\Support\LegalCatalogue;
use App\Support\SettingsRegistry;
use App\Support\TicketDocumentRequirements;
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
     * **سقف المخرج لكلّ مهمّة.** كان 2048 لكلّ نداء، ولائحةُ دعوى عربيّة بأسانيدها تتجاوزه:
     * قيسَ في المتصفّح (2026-09-11) مخرجٌ يُبتر داخل حقل `source_excerpt` بعد نحو ستّة آلاف
     * حرف، فيفشل `json_decode` ويُحفظ JSON خامٌ مسودّةً. تُرفع اللائحة وحدها — لا إنفاق كلّ نداء.
     */
    public static function maxTokensFor(?string $promptId): int
    {
        return match ($promptId) {
            'case.pleading' => 8192,
            default => 2048,
        };
    }

    /** مهلة الطلب — مخرجٌ أطول يحتاج وقتاً أطول، وإلا انقطع قبل أن يكتمل. */
    public static function timeoutFor(?string $promptId, int $default): int
    {
        return $promptId === 'case.pleading' ? max($default, 120) : $default;
    }

    /**
     * معرّف المصدر ⇐ استشهادٌ يُقرأ: «المادة … من نظام …». المعرّف (`LS-CIVIL-281`) مفتاحُ
     * مطابقةٍ خادميّ في `claims` — لا يعني شيئاً للمحكمة ولا للعميل.
     *
     * @param  iterable<LegalSource>  $sources
     * @return array<string, string>
     */
    public static function citationMap(iterable $sources): array
    {
        $map = [];
        foreach ($sources as $s) {
            $map[(string) $s->ref] = $s->article_no
                ? "المادة {$s->article_no} من {$s->system_name}"
                : trim("{$s->system_name} — {$s->title}", ' —');
        }

        return $map;
    }

    /**
     * **نصٌّ يُقدَّم للمحكمة بلا رموزٍ داخليّة.** (قيسَ في المتصفّح 2026-09-11)
     *
     * - معرّف مصدرٍ معتمد ⇐ استشهاده المقروء؛ ومعرّفٌ لا يقابله مصدر ⇐ «(سندٌ غير مُتحقَّق)».
     * - رموز Markdown تُزال: المحرّر والمحادثة نصٌّ عاديّ، فكانت النجوم تظهر حرفيّاً.
     *
     * @param  array<string, string>  $citations  من `citationMap`
     */
    public static function humanizeDraft(string $text, array $citations): string
    {
        // كياناتٌ مرمَّزة (&quot;) وهروبات JSON بقيت حرفيّة (\n \t \uXXXX) — لا مكان لها في لائحة
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn (array $m) => mb_chr((int) hexdec($m[1]), 'UTF-8'), $text);
        $text = str_replace(['\\n', '\\t', '\\"'], ["\n", ' ', '"'], $text);
        // أسوار الشيفرة وعلامات الشيفرة، ونقاطُ Markdown بالنجمة ⇐ «•»
        $text = (string) preg_replace('/^```[a-zA-Z]*\s*$/mu', '', $text);
        $text = str_replace(['```', '`'], '', $text);
        $text = (string) preg_replace('/^(\s*)\*\s+/mu', '$1• ', $text);

        $text = (string) preg_replace_callback(
            '/[(\[]?\s*\b(LS-[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*)\b\s*[)\]]?/u',
            fn (array $m) => isset($citations[$m[1]]) ? '('.$citations[$m[1]].')' : '(سندٌ غير مُتحقَّق)',
            $text
        );
        $text = (string) preg_replace('/\*\*(.+?)\*\*/su', '$1', $text);
        $text = str_replace(['**', '__'], '', $text);

        return (string) preg_replace('/^#{1,6}\s*/mu', '', $text);
    }

    /**
     * **استخلاص نصّ المسودّة من JSON مبتور.** حقلُ `draft` يسبق الأسانيد في المخرج، فيكتمل
     * غالباً وإن بُتر ما بعده. يُفكّ بوصفه سلسلةَ JSON (الأسطر `\n` تصير أسطراً) — لا يُحفظ
     * JSON خامٌ في المحرّر ولا في المحادثة.
     *
     * @return array{draft: string, complete: bool}|null `complete` = أُغلقت سلسلة `draft` نفسها
     */
    public static function salvageDraft(?string $raw): ?array
    {
        if ($raw === null || ! preg_match('/"draft"\s*:\s*"((?:[^"\\\\]|\\\\.)*)(")?/su', $raw, $m)) {
            return null;
        }

        $body = $m[1];
        $complete = ($m[2] ?? '') === '"';
        if (! $complete) {
            // هروبٌ مبتور في الذيل (`\` أو `\u12`) يُفسد فكّ السلسلة كلّها
            $body = (string) preg_replace('/\\\\(u[0-9a-fA-F]{0,3})?$/', '', $body);
        }

        $text = json_decode('"'.$body.'"');
        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        return ['draft' => trim($text), 'complete' => $complete];
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
        return $this->summarizeResult($ticket)['summary'];
    }

    /**
     * ملخّص الملفّ **مع تتبّع النداء ومصدره** — ليُقيَّد في `ai_runs`.
     *
     * `ticket.summary` مصنَّفة `high` في `AiPolicyGate`، ومخرجها رأيٌ قانونيّ رباعيّ
     * يصل المحامي ثم العميل بعد الاعتماد — وكان يُنتَج **بلا قيدٍ واحد**: لا كلفة
     * محسوبة ولا نموذج معروف ولا وجودَ له في صندوق المراجعة الذي يفرضه P4.
     *
     * @return array{summary:array<string,mixed>, meta:array<string,mixed>, source:AiSource}
     */
    public function summarizeResult(Ticket $ticket): array
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

        $meta = [];

        try {
            $call = $this->runCall(AiPromptRegistry::ticketSummarySystem(), [['role' => 'user', 'content' => $prompt]], json: true, promptId: 'ticket.summary');
            $meta = self::callMeta($call, 'ticket.summary');

            // `AiOutputValidator::ticketSummary` بدل التحقّق اليدويّ: كان هذا الموضع
            // يعيد بناء ما يفعله المحقّق (تنقيط القوائم وسقوط `case_summary` الفارغ)
            // بنسخةٍ ثانية تتباعد عنه عند أي تعديل.
            $valid = AiOutputValidator::ticketSummary(self::parseJsonResponse($call->text));

            if ($valid !== null) {
                return [
                    'summary' => $valid + ['ai_generated' => true], // تحليل ذكاء اصطناعي حقيقي
                    'meta' => $meta,
                    'source' => AiSource::AiSuccess,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService summarize failed: '.$e->getMessage());
        }

        // تعذّر الـAI: قالب احتياطي مع علَم صريح أنه ليس تحليلاً حقيقياً
        return [
            'summary' => $this->fallbackSummary($ticket) + ['ai_generated' => false],
            'meta' => $meta,
            'source' => AiSource::Fallback,
        ];
    }

    /**
     * اقتراح الذكاء الاصطناعي لمسار مآل التذكرة مع بيان السبب الحقيقي (القرارات الأربعة).
     *
     * @return array{track: string, reason: string}
     */
    public function suggestTicketTrack(Ticket $ticket, ?array $summaryParts = null): array
    {
        $subject = (string) $ticket->subject;
        $type = (string) $ticket->type;
        $dept = (string) $ticket->department;
        $claimAmount = (int) $ticket->claim_amount;
        $courtName = (string) $ticket->court_name;
        $convoText = $ticket->messages()->where('who', '!=', 'note')->pluck('body')->implode(' ');
        $docNames = $ticket->documents()->pluck('name')->implode(' ');
        $docTypes = $ticket->documents()->pluck('doc_type')->implode(' ');
        $docSummaries = $ticket->documents()->pluck('summary')->implode(' ');

        $allText = $subject.' '.$type.' '.$dept.' '.$convoText.' '.$docNames.' '.$docTypes.' '.$docSummaries;
        if ($summaryParts) {
            $allText .= ' '.($summaryParts['case_summary'] ?? '').' '.($summaryParts['key_points'] ?? '').' '.($summaryParts['facts'] ?? '');
        }

        // 1. مسار التنفيذ القضائي: وجود سند تنفيذي (سند لأمر / شيك / حكم قطعي / عقد إيجار موحد / محضر صلح)
        $execKeywords = ['سند لأمر', 'شيك', 'محكمة التنفيذ', 'سند تنفيذي', 'حكم قطعي', 'حكم نهائي', 'عقد إيجار موحد', 'محضر صلح', 'قرار تحكيم', 'سند تنفيذ'];
        foreach ($execKeywords as $kw) {
            if (mb_stripos($allText, $kw) !== false) {
                return [
                    'track' => 'execution',
                    'reason' => "تبيّن حيازة المستفيد لسند تنفيذي مكتمل الأركان والنفاذ («{$kw}»)، مما يتيح التقدم المباشر إلى محكمة التنفيذ عبر منصة ناجز دون حاجة لرفع دعوى موضوعية جديدة.",
                ];
            }
        }

        // 2. مسار الإلغاء / الحفظ المسبب: خروج عن الاختصاص أو تقادم أو انعدام صفة
        $dropKeywords = ['خارج الاختصاص', 'انقضاء بالتقادم', 'فوات الميعاد', 'عدم صحة السند', 'بلا اختصاص'];
        foreach ($dropKeywords as $kw) {
            if (mb_stripos($allText, $kw) !== false) {
                return [
                    'track' => 'close',
                    'reason' => "الموضوع ينطوي على مانع نظامي أو يخرج عن نطاق اختصاص المكتب القضائي («{$kw}»)، مما يقتضي حفظ الملف بقرار مسبب صريح تلافياً لاستنزاف أتعاب العميل.",
                ];
            }
        }

        // 3. مسار القضية والترافع: نزاع قضائي موضوعي أو مطالبة مالية أو محكمة محددة
        if ($claimAmount > 0 || ! empty($courtName) || mb_stripos($allText, 'دعوى') !== false || mb_stripos($allText, 'محكمة') !== false || mb_stripos($allText, 'مطالبة مالية') !== false || mb_stripos($allText, 'فسخ') !== false || mb_stripos($allText, 'تعويض') !== false) {
            return [
                'track' => 'case',
                'reason' => 'الوقائع والمستندات تُظهر نزاعاً قضائياً موضوعياً مكتمل الأركان وتوافر صفة الخصومة، مما يستوجب قيد صحيفة دعوى رسمية والترافع أمام المحكمة المختصة لصيانة الحقوق.',
            ];
        }

        // 4. مسار الاستشارة القانونية: استيضاح المسألة وجلسة المشورة
        return [
            'track' => 'consultation',
            'reason' => 'الموضوع يتطلب دراسة تفصيلية وبحثاً للخيارات والبدائل النظامية، ويستلزم عقد جلسة استشارية متخصصة مع المستشار لبناء الرأي القانوني والمشورة المناسبة.',
        ];
    }

    /**
     * التصنيف **مع تتبّع النداء** — ليُقيَّد في `ai_runs` كبقيّة المخرجات.
     *
     * كان `ClassifyConvertedCaseJob` يخرج مبكراً حين يطابق مخرجُ النموذج الاحتياطيَّ،
     * فلا يُكتب قيدٌ ولا تُحصى كلفة: نداءٌ جرى ودُفع ثمنه ولا أثر له. وقيس حيّاً —
     * قضيّةٌ محوَّلة بلا قيد ذكاء واحد. و«لم يُفِد بجديد» معلومةٌ تقييميّة لا سبب صمت.
     *
     * @return array{classification:array{type:string,department:string}, meta:array<string,mixed>, source:AiSource}
     */
    public function classifyCaseResult(Ticket $ticket): array
    {
        $convo = $ticket->messages()->where('who', '!=', 'note')->orderBy('id')->get()
            ->map(fn ($m) => ($m->who === 'client' ? 'العميل: ' : 'الفريق: ').AiContextBuilder::prepare((string) $m->body))
            ->implode("\n");

        $system = AiPromptRegistry::caseClassifySystem();
        $prompt = "نوع التذكرة: {$ticket->type}\nالقسم الحالي: {$ticket->department}\n\nالمحادثة:\n{$convo}";

        $meta = [];

        try {
            $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], json: true, promptId: 'case.classify');
            $meta = self::callMeta($call, 'case.classify');
            if ($call->text) {
                $data = self::parseJsonResponse($call->text);
                if (is_array($data) && ! empty($data['type'])) {
                    return [
                        'classification' => [
                            'type' => (string) $data['type'],
                            'department' => (string) ($data['department'] ?? $ticket->department),
                        ],
                        'meta' => $meta,
                        'source' => AiSource::AiSuccess,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService classifyCase failed: '.$e->getMessage());
        }

        return [
            'classification' => self::fallbackClassification($ticket),
            'meta' => $meta,
            'source' => AiSource::Fallback,
        ];
    }

    /**
     * التصنيف الاحتياطي الحتمي — بلا أي نداء خارجي.
     *
     * يُستخرج كي يُنادى من موضعين بلا نسخ: من classifyCaseResult عند تعذّر المزوّد، ومن
     * CaseConversion التي صارت تُنشئ القضية بهذه القيم فوراً ثم تُنقّحها مهمّة مطابورة —
     * فنداء AI متزامن كان يحبس طلب التحويل **12.3 ثانية** مقاسة، وحدّ FPM ثلاثون.
     *
     * @return array{type: string, department: string}
     */
    public static function fallbackClassification(Ticket $ticket): array
    {
        // بلا قسم: القسم العامّ في الكتالوج (دُمجت فيه الاستشارات والترافع والصياغة)
        $general = LegalCatalogue::general();
        $fallback = $general !== null ? $general->name : 'الاستشارات القانونية';

        return ['type' => $ticket->type, 'department' => $ticket->department ?: $fallback];
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
        // **التمويه قبل المغادرة.** نصّ العميل يصل هنا خاماً من المتحكّم، وكان يُدرَج
        // في التعليمة كما هو — فيغادر رقمُ الهويّة والجوّال إلى المزوّد الخارجيّ.
        // كشفه أوّل تشغيل حقيقيّ: دليل الحمولة عاد بـ`masked: []` على نصٍّ يحوي هويّةً
        // وجوّالاً. والفرز لا يحتاج معرّفاً أصلاً — يحتاج موضوع الطلب.
        $safeDetails = AiContextBuilder::prepare($details);
        $prompt = "نوع التذكرة: {$ticket->type}\nالقسم الذي اختاره العميل: ".($ticket->department ?: '—')."\nتفاصيل الطلب:\n{$safeDetails}";

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
     * يُعيد {related, doc_type, summary, reason, requirements} أو null عند تعذّر الفحص (لا مزوّد/نوع غير مدعوم).
     * `requirements`: بنود قائمة القسم التي قال النموذج إنّ الملفّ يستوفيها — خامٌ غير مصفّى.
     *
     * @return array{related: bool, doc_type: string, summary: string, reason: string, requirements: mixed}|null
     */
    public function analyzeDocument(Ticket $ticket, TicketDocument $doc): ?array
    {
        $abs = Storage::disk('local')->path($doc->path);
        if (! is_file($abs) || $doc->size > 8 * 1024 * 1024) {
            return null; // ملف مفقود أو أكبر من حد الفحص
        }

        // قائمة مستندات القسم بإلزامها واستيفائها — منها يختار النموذج ما يستوفيه الملفّ (v2)
        $requirements = TicketDocumentRequirements::forPrompt($ticket);
        $subject = AiContextBuilder::prepare((string) $ticket->messages()->where('who', 'client')->first()?->body) ?: $ticket->type;

        $prevDocs = $ticket->documents()->where('id', '!=', $doc->id)->get();
        $prevDocsInfo = '';
        if ($prevDocs->isNotEmpty()) {
            $prevDocsInfo = "\nالمرفقات السابقة المرفوعة:\n".$prevDocs->map(fn ($d) => "- اسم الملف: {$d->name} | النوع: {$d->doc_type} | الملخص: {$d->summary} | الحالة: {$d->status}")->implode("\n");
        }

        $system = AiPromptRegistry::documentAnalyzeSystem();
        $context = "نوع التذكرة: {$ticket->type}\nالقسم: {$ticket->department}\nموضوع العميل: {$subject}\nقائمة المستندات المطلوبة لقسم الطلب:\n{$requirements}\nاسم الملف: {$doc->name}{$prevDocsInfo}";

        $call = new AiCallResult(text: null, traceId: (string) Str::uuid(), durationMs: 0, failureCode: AiFailure::PROVIDER_ERROR);

        try {
            $ext = strtolower(pathinfo($doc->name, PATHINFO_EXTENSION));
            $json = null;

            if ($text = $this->extractText($abs, $ext)) {
                // محتوى نصي مستخرج — يمر عبر سلسلة المزوّدين المعتادة.
                // `runCall` لا `run`: الأخيرة تعيد النصّ وحده فتُهدر بيانات التتبّع —
                // وبها كان فحص المستند بلا نموذج ولا زمن ولا كلفة في السجلّ.
                $prompt = $context."\n\nمحتوى المستند:\n".AiContextBuilder::prepare($text, 20000)."\n\nافحص المحتوى وأعد JSON.";
                $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], json: true, promptId: 'document.analyze');
                $json = $call->text;
            } elseif (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true) && ! empty(config('services.gemini.key'))) {
                // ملف ثنائي — فحص متعدد الوسائط عبر Gemini (خارج البوّابة: مسار خاصّ)
                $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext];
                $startedAt = microtime(true);
                $json = $this->viaGeminiDocument($system, $context."\n\nافحص المستند المرفق وأعد JSON.", base64_encode((string) file_get_contents($abs)), $mime);
                $call = new AiCallResult(
                    text: $json,
                    traceId: (string) Str::uuid(),
                    durationMs: (int) round((microtime(true) - $startedAt) * 1000),
                    provider: 'gemini',
                    model: AiModelRouter::modelFor('gemini', 'document.analyze'),
                    failureCode: $json === null ? AiFailure::PROVIDER_ERROR : null,
                );
            }

            if ($json && ($data = self::parseJsonResponse($json))) {
                $valid = AiOutputValidator::documentAnalysis($data);
                if ($valid !== null) {
                    return $valid + [
                        // الأسماء كما أعادها النموذج — يصفّيها `TicketDocumentRequirements::matchItems`
                        // على قائمة القسم عند الحفظ، فلا يُقبل بندٌ ليس فيها
                        'requirements' => $data['requirements'] ?? [],
                        'source' => AiSource::AiSuccess->value,
                        'meta' => self::callMeta($call, 'document.analyze'),
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

        $call = new AiCallResult(text: null, traceId: (string) Str::uuid(), durationMs: 0, failureCode: AiFailure::PROVIDER_ERROR);

        // اسم المكتب من إعداده عبر `AiPromptRegistry::withOffice` — القارئ الواحد لكلّ التعليمات
        $system = AiPromptRegistry::withOffice('أنت مساعد قانوني في «{office}» بالسعودية. اقرأ محتوى المستند المرفق بملف القضية كاملاً، ')
            .'صنّف نوعه ولخّص محتواه بإيجاز مفيد للمحامي. أعد JSON فقط: '
            .'{"doc_type":"نوع المستند كما فهمته من محتواه","summary":"ملخّص محتوى المستند في سطر أو سطرين"}. لا نص خارج JSON.';
        $context = "نوع القضية: {$case->type}\nالقسم: ".($case->department ?? '—')."\nاسم الملف: {$doc->name}";

        try {
            $ext = strtolower(pathinfo($doc->name, PATHINFO_EXTENSION));
            $json = null;

            if ($text = $this->extractText($abs, $ext)) {
                $prompt = $context."\n\nمحتوى المستند:\n".AiContextBuilder::prepare($text, 20000)."\n\nلخّص المحتوى وصنّفه وأعد JSON.";
                // `runCall` لا `run`: الأخيرة تعيد النصّ وحده فتُهدر النموذج والزمن
                // والكلفة — وبها كان فحص مستند القضيّة يجري **بلا قيدٍ في `ai_runs`**،
                // نظير ما أُصلح في فحص مستند التذكرة.
                $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], json: true, promptId: 'document.analyze');
                $json = $call->text;
            } elseif (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true) && ! empty(config('services.gemini.key'))) {
                $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext];
                $json = $this->viaGeminiDocument($system, $context."\n\nلخّص المستند المرفق وصنّفه وأعد JSON.", base64_encode((string) file_get_contents($abs)), $mime);
            }

            if ($json && ($data = self::parseJsonResponse($json)) && (isset($data['summary']) || isset($data['doc_type']))) {
                return [
                    'doc_type' => (string) ($data['doc_type'] ?? 'مستند'),
                    'summary' => (string) ($data['summary'] ?? ''),
                    'meta' => self::callMeta($call, 'document.analyze'),
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
     *
     * غلافٌ نصّيّ فوق `draftPleadingResult` — يبقى للمستدعين الذين لا يحتاجون التتبّع.
     */
    public function draftPleading(LegalCase $case): string
    {
        return $this->draftPleadingResult($case)['draft'];
    }

    /**
     * المسودّة **مع حصيلة الاستشهاد وتتبّع النداء**.
     *
     * فُصلت عن `draftPleading` لأن قيد `ai_runs` يُكتب في طبقة الأعمال (كما في
     * `ExecService` و`TicketTriage`)، وهي كانت تُرجع نصّاً وحده فتضيع الحصيلة:
     * مسودّة لائحة — أخطر مخرجٍ قانونيّ في المنظومة — كانت تُولَّد **بلا قيدٍ واحد**
     * في سجلّ القرارات، فلا كلفتها محسوبة ولا نموذجها معروف ولا مراجعتها مطلوبة.
     *
     * @return array{draft:string, meta:array<string,mixed>, source:AiSource, verdict:?string}
     */
    public function draftPleadingResult(LegalCase $case): array
    {
        $notes = [];
        $result = $this->composePleading($case, $notes);

        // تنبيهات نقص الملفّ تُلحق بالنصّ — و⚠️ يمنع اعتماده حتى يعالجها المحامي
        // (`CasePleading::hasWarnings`). والاحتياطيّ ممنوعُ الاعتماد أصلاً فلا يُثقَل بها.
        if ($notes !== [] && $result['source'] !== AiSource::Fallback) {
            $result['draft'] = rtrim((string) $result['draft'])."\n\n"
                .implode("\n", array_map(fn (string $n) => '⚠️ '.$n, $notes));
        }

        return $result;
    }

    /** @param  array<int, string>  $notes  تنبيهاتٌ عن نقص الملفّ تُلحق بالمسودّة */
    private function composePleading(LegalCase $case, array &$notes): array
    {
        // سياق القضية الحقيقي (لا اختلاق): وقائع الملخّص + بيانات الخصم + مستندات الملفّ كلّه + ما كتبه العميل
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

        // **كلّ مستندات الملفّ منذ بدايته** — مرفقات الطلب قبل التحويل (`ticket_documents`) ومستندات
        // القضيّة بعده. كانت الأولى تُسقَط كلّها، والثانية تُسقَط صامتةً ما لم يكتمل تحليلها.
        $ticketDocs = $ticket ? $ticket->documents()->get() : collect();
        $caseDocs = $case->documents()->get();
        $analysed = fn ($d) => trim((string) $d->summary) !== '';
        $docs = $ticketDocs->filter($analysed)
            ->map(fn ($d) => '- (مرفق الطلب) '.($d->doc_type ?: 'مستند').': '.$d->summary)
            ->merge($caseDocs->filter($analysed)->map(fn ($d) => '- (مستند القضية) '.($d->doc_type ?: 'مستند').': '.$d->summary))
            ->implode("\n");
        $pendingDocs = $ticketDocs->reject($analysed)->count() + $caseDocs->reject($analysed)->count();

        // **ما كتبه العميل بيده** — في محادثة القضيّة بعد التحويل، وفي طلبه حين لا ملخّص له.
        // رسائل الإرفاق وحدها تُستبعد: المستند نفسه حاضرٌ بملخّصه أعلاه.
        $clientText = fn ($messages) => $messages
            ->map(fn ($m) => trim(html_entity_decode(strip_tags((string) $m->body), ENT_QUOTES | ENT_HTML5, 'UTF-8')))
            ->reject(fn (string $t) => $t === '' || str_starts_with($t, 'تم إرفاق مستند'))
            ->map(fn (string $t) => '- '.AiContextBuilder::clip($t, 600))
            ->implode("\n");
        $caseClientMsgs = $clientText($case->messages()->where('who', 'client')->reorder('id', 'desc')->limit(8)->get()->reverse());
        $ticketClientMsgs = ($summary === null && $ticket !== null)
            ? $clientText($ticket->messages()->where('who', 'client')->reorder('id')->limit(8)->get())
            : '';

        // تمويه المعرّفات قبل مغادرة الخادم — هذا المسار كان يُرسل السياق خاماً.
        // حقول الملخّص ليست «آمنة لأنها مولَّدة»: النموذج يردّد ما ورد في نصّ العميل،
        // فرقمُ هويّةٍ كتبه العميل في تذكرته يعود في «الوقائع» ثم يغادر مرّةً ثانية.
        // والقاعدة في المنظومة أن التمويه عند الحدّ لا عند المصدر.
        $facts = array_map(fn ($f) => AiContextBuilder::prepare($f), $facts);
        $docs = AiContextBuilder::prepare($docs);
        $parties = array_map(fn ($f) => AiContextBuilder::prepare($f), $parties);
        $caseClientMsgs = AiContextBuilder::prepare($caseClientMsgs, 4000);
        $ticketClientMsgs = AiContextBuilder::prepare($ticketClientMsgs, 4000);
        $subject = AiContextBuilder::prepare((string) ($ticket?->subject ?? ''), 500);

        // بلا رقم ملفّ المكتب: كان النموذج يكتبه «القضية رقم CASE-…» — ورقم الدعوى يصدر من المحكمة عند القيد
        $context = "نوع القضية: {$case->type}\nالقسم: ".($case->department ?? '—');
        if ($parties !== []) {
            $context .= "\n".implode("\n", $parties);
        }
        if ($subject !== '') {
            $context .= "\nموضوع الطلب: {$subject}";
        }
        if ($facts !== []) {
            // الوسم يتبع الحالة: «المعتمدة» كان يُكتب لكلّ ملخّص، معتمداً كان أم مسودّةً آليّة
            $context .= "\n\n".($summary?->isApproved()
                ? 'وقائع وبيانات الملف (المعتمدة):'
                : 'وقائع الملف (ملخّصٌ لم يُعتمد بعد — يُراجَع قبل الاعتماد):')."\n".implode("\n", $facts);
        }
        if ($ticketClientMsgs !== '') {
            $context .= "\n\nما كتبه العميل في طلبه:\n".$ticketClientMsgs;
        }
        if ($docs !== '') {
            $context .= "\n\nملخّصات مستندات الملف (مرفقات الطلب ومستندات القضية):\n".$docs;
        }
        if ($caseClientMsgs !== '') {
            $context .= "\n\nما كتبه العميل في محادثة القضية:\n".$caseClientMsgs;
        }
        if ($pendingDocs > 0) {
            $context .= "\n\nمستندات لم يكتمل تحليلها بعد: {$pendingDocs} — لا تفترض محتواها.";
        }

        // **ملفٌّ بلا وقائع لا يُسرَد له نزاعٌ متخيَّل** — قيسَ على قضيّة تجربة بلا وقائع: كتب النموذج
        // «عقد شراكة» و«محاولات حلٍّ وديّ» من عنده. يُقال له صراحةً، وتُوسم المسودّة.
        $hasFacts = $facts !== [] || $docs !== '' || $caseClientMsgs !== '' || $ticketClientMsgs !== '';
        if (! $hasFacts) {
            $context .= "\n\nلا وقائع مسجّلة في الملف بعد: اكتب الأقسام قالباً بعبارة (يُستكمل) دون أيّ سردٍ للوقائع.";
            $notes[] = 'الملف بلا وقائع مسجّلة — المسودّة قالبٌ عامّ، ولا تُعتمد قبل استكمال الوقائع من المستندات.';
        }
        if ($pendingDocs > 0) {
            $notes[] = "{$pendingDocs} من مستندات الملف لم يكتمل تحليلها وقت التوليد — أعد التوليد بعد اكتماله أو راجِعها يدوياً.";
        }

        // استرجاع قانونيّ موثَّق — يُضيف ولا يَحجب: قاعدة فارغة ⇒ المسار كما هو تماماً،
        // فلا تتوقّف مسودّة عاملة اليوم بسبب ميزة لم يُغذَّ محتواها بعد. ووجود مصادر
        // يجعل الاستشهاد مطلوباً وقابلاً للمطابقة خادمياً.
        // **الاستعلام من وقائع الملفّ لا من نوعه.** كان `query: $case->type` — أي كلمة
        // واحدة مثل «تجاري» لا ترد في نصّ نظاميّ، فيعود الاسترجاع بصفر مصادر ويُخرج
        // المسار «لا سند كافٍ» ومسودّةً فارغة: مسارُ الاستشهاد معطَّلٌ عملياً رغم 786
        // مادّة معتمدة. أثبته القياس: «تجاري» ⇒ صفر، و«فسخ عقد توريد والتعويض» ⇒ ستّة.
        //
        // والمصدر الصحيح `$facts` المجموعة أعلاه (ملخّص التذكرة ووقائعها ونقاطها) —
        // وهي نصّ الملفّ الفعليّ. والقضية نفسها لا تملك حقل موضوع أصلاً.
        $sources = LegalKnowledge::retrieve(
            domain: (string) ($case->department ?: $case->type),
            query: AiContextBuilder::clip(trim(implode(' ', $facts).' '.$subject.' '.$docs.' '.$case->type), 3000),
        );
        $authority = LegalKnowledge::asContext($sources);
        if ($authority !== '') {
            $context .= "\n\nمصادر نظاميّة معتمدة (استشهد بمعرّفاتها حصراً، ولا تذكر مادّة خارجها):\n".$authority;
        }

        $system = AiPromptRegistry::casePleadingSystem();
        $prompt = $context."\n\nاكتب مسودة لائحة الدعوى بناءً على ما سبق فقط.";

        // **لا حجب عند خلوّ السند.** جرّبتُ الحجب (إعادة `insufficientAuthority` قبل
        // النداء) فأسقط اختبارين قائمين، وهما محقّان: مجالٌ لم تُغذَّ مصادره بعد يفقد
        // المسودّة كلّها — ووقائعها وطلباتها وأطرافها لا تحتاج سنداً نظامياً أصلاً.
        //
        // والحماية لا تحتاج حجباً: `existingRefs` على قائمة فارغة تعيد فارغاً، فيسقط
        // **كل** ادّعاءٍ نظاميّ إلى «غير مدعوم» ويظهر تحت لافتته. أي أن التحقّق نفسه
        // يفرض الصدق بلا أن يُلغي الوظيفة.
        $meta = [];

        try {
            $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], json: true, promptId: 'case.pleading');
            $text = $call->text;
            $meta = self::callMeta($call, 'case.pleading');

            // ⚠️ الحكم للخادم: `existingRefs` تُطابق ما ادّعاه النموذج بجدول المصادر،
            // فمعرّفٌ لا يقابله صفٌّ معتمد يسقط إلى «غير مدعوم» مهما بدا معقولاً.
            // كان هذا العقد كلّه مبنيّاً ولا يُستدعى إلا في التقييم: اللائحة تُولَّد
            // نصّاً حرّاً بلا مطابقة، أي أن الـ786 مادّة موصولة بالسياق ومفصولة عن
            // التحقّق — استشهادٌ بلا تدقيق، وهو ما قامت المنظومة على منعه.
            $validated = LegalClaims::validate(
                self::parseJsonResponse($text),
                LegalKnowledge::existingRefs($sources->pluck('ref')->all()),
            );

            if ($validated !== null) {
                return [
                    'draft' => $this->renderPleading((string) $case->number, $validated, 'مسودّة لائحة دعوى', self::citationMap($sources)),
                    'meta' => $meta,
                    // المصدر يتبع الحكم لا شكل المخرج: لائحةٌ سليمة البنية بلا ادّعاءٍ
                    // مسنَدٍ واحد ليست تحليلاً اجتاز التحقّق — وبوّابة السياسة تفرض
                    // عليها مراجعةً بشريّة بدل عرضها بوصفها مسنَدة.
                    'source' => LegalClaims::isCitable($validated)
                        ? AiSource::AiSuccess
                        : AiSource::ManualRequired,
                    'verdict' => $validated['verdict'],
                ];
            }

            // **JSON مبتور** — حقلُ `draft` يسبق الأسانيد فيكتمل غالباً. يُستخلص نصّاً لا JSON
            // خاماً، ويُوسم في النصّ نفسه أنّ أسانيده لم تُطابَق (الوسم في `ai_runs` وحده لا يراه المحامي).
            $salvaged = self::salvageDraft($text);
            if ($salvaged !== null) {
                return [
                    'draft' => self::humanizeDraft($salvaged['draft'], self::citationMap($sources))."\n\n⚠️ ".($salvaged['complete']
                        ? 'انقطع مخرج النموذج قبل سند الادّعاءات — لم يُتحقَّق من أسانيد هذه المسودّة، فراجِعها قبل الاعتماد.'
                        : 'انقطع نصّ المسودّة قبل اكتماله — أكمله في المحرّر، ولم يُتحقَّق من أسانيد هذه المسودّة.'),
                    'meta' => $meta,
                    'source' => AiSource::ManualRequired,
                    'verdict' => null,
                ];
            }

            // JSON غير مفهوم: النصّ الخام أنفع من لا شيء، لكنه **بلا وسم استشهاد**
            // فلا يُقرأ بوصفه مسنَداً — ويُقال ذلك في النصّ نفسه.
            if ($text && trim($text) !== '') {
                return [
                    'draft' => self::humanizeDraft(trim($text), self::citationMap($sources))."\n\n⚠️ تعذّر فهم بنية مخرج النموذج — لم يُتحقَّق من أسانيد هذه المسودّة.",
                    'meta' => $meta,
                    // مخرجٌ لم يجتز التحقّق البرمجيّ: بتعريف `AiSource` نفسه ليس `AiSuccess`.
                    // تسميته نجاحاً تُخفي أن الاستشهاد لم يُطابَق بقاعدة المصادر.
                    'source' => AiSource::ManualRequired,
                    'verdict' => null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService draftPleading failed: '.$e->getMessage());
        }

        // عند تعذّر الـAI: لا لائحة مُختلَقة — رسالة أمينة تدفع للتحرير اليدوي
        return [
            'draft' => "مسودّة لائحة دعوى — {$case->number}\n\n"
                .'تعذّر توليد المسودّة بالذكاء الاصطناعي حالياً. يُرجى إعادة المحاولة لاحقاً، '
                .'أو تحرير لائحة الدعوى يدوياً (الوقائع ثم الأسانيد النظامية ثم الطلبات) بحسب مستندات القضية.',
            'meta' => $meta,
            'source' => AiSource::Fallback,
            'verdict' => null,
        ];
    }

    /**
     * يعرض المسودّة المُتحقَّقة على المحامي **مع سند كل ادّعاء**.
     *
     * الخطة (P2) تفرض «إرفاق مصفوفة ادّعاء ← مصدر ← مقطع، وتحديد الجمل غير المدعومة
     * بعلامة واضحة». والعلامة هنا **فوق النصّ لا تحته**: مسودّةٌ فيها ادّعاءٌ بلا سند
     * تُقرأ كاملةً مسنَدة إن لم يُصرَّح بالنقص في موضعٍ لا يُتجاوَز.
     *
     * @param  array{draft:string,claims:array<int,array<string,string>>,unsupported_claims:array<int,string>,verdict:string}  $result
     */
    private function renderPleading(string $ref, array $result, string $kind = 'مسودّة لائحة دعوى', array $citations = []): string
    {
        $out = self::humanizeDraft(trim((string) $result['draft']), $citations);

        // «لا سند كافٍ» **لا يُلغي المسودّة**: الوقائع والأطراف والطلبات لا تحتاج
        // مادّةً نظاميّة، وإلغاؤها يُضيّع عملاً صحيحاً. يُلغيها فقط ألّا يكون هناك نصّ.
        if (($result['verdict'] ?? '') === LegalClaims::INSUFFICIENT_AUTHORITY) {
            if ($out === '') {
                // ⚠️ في أوّله: ليست لائحةً تُعتمد — `CasePleading::blockReason` يمنع اعتمادها
                return "⚠️ {$kind} — لم تُنتَج\n\n"
                    .'لم يُعثر في قاعدة المصادر المعتمدة على نصٍّ نظاميّ يحكم وقائع هذا الملفّ، '
                    ."ولم يُنتج النموذج مسودّة.\n\n"
                    .'المطلوب من المحامي: تحديد الأساس النظاميّ يدوياً، أو إضافة المصدر المعتمد '
                    .'إلى قاعدة المصادر ثم إعادة التوليد. لم تُقترح أيّ مادّة تلقائياً — '
                    .'اقتراحُ مادّةٍ بلا سندٍ مُتحقَّق أخطر من غيابها.';
            }

            return $out."\n\n⚠️ لا سند نظاميّ مُتحقَّق لهذه المسودّة. لم تُطابَق أيّ مادّةٍ "
                .'بقاعدة المصادر المعتمدة، فالأسانيد الواردة أعلاه — إن وُجدت — غير موثَّقة. '
                .'يلزم المحامي تحديد الأساس النظاميّ وتوثيقه قبل التقديم.';
        }

        // **لا ملحقَ «سند الادّعاءات» في النصّ.** كان يُلحق كلَّ ادّعاءٍ بمعرّفه ومقطعه
        // (`[LS-CIVIL-281] …`) — بياناتُ مراجعةٍ لا فقرةٌ من لائحة، فتصل المحكمةَ والعميل
        // رموزاً. المطابقة نفسها باقية: `claims` و`verdict` في القيد، والاستشهاد مقروءٌ في المتن.

        if (! empty($result['unsupported_claims'])) {
            $out .= "\n\n⚠️ ادّعاءات بلا سندٍ مُتحقَّق — لا تُقدَّم للمحكمة قبل توثيقها (وثّقها ثم احذف هذا التنبيه):\n";
            foreach ($result['unsupported_claims'] as $u) {
                $out .= "\n• ".self::humanizeDraft((string) $u, $citations);
            }
        }

        return $out;
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
        return $this->replyResult($ticket, $clientMessage)['text'];
    }

    /**
     * الردّ **مع تتبّعه** — يصل العميل مباشرةً، فلا يجوز أن يُنتَج بلا أثر.
     *
     * حساسيّته `low` ⇒ `AiPolicyGate` يقبله بلا عتبة، فالقيد هنا **إحصاءٌ وكلفةٌ
     * وتتبّع** لا إثقالٌ لصندوق المراجعة. وتغييرُ الحساسيّة قرارٌ منفصل.
     *
     * @return array{text:?string, meta:array<string,mixed>, source:AiSource}
     */
    public function replyResult(Ticket $ticket, string $clientMessage): array
    {
        $system = AiPromptRegistry::chatReplySystem()."\n\n".$this->context($ticket);

        return self::chatOutcome($this->runCall($system, $this->history($ticket, $clientMessage), promptId: 'chat.reply'));
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
        // نصّ العميل مموَّهاً: صياغة الترحيب لا تحتاج هويّته ولا جوّاله
        $prompt = "نوع الطلب: {$ticket->type}\nما كتبه العميل:\n".AiContextBuilder::prepare($details);

        try {
            $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], promptId: 'chat.reply');
            $this->lastChatOutcome = self::chatOutcome($call);
            if ($this->lastChatOutcome['text'] !== null) {
                return $this->lastChatOutcome['text'];
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
        $prompt = "نوع الطلب: {$ticket->type}\nما كتبه العميل:\n".AiContextBuilder::prepare($details)
            ."\nالمستندات المرفقة ذات الصلة:\n{$docLines}";

        try {
            $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], promptId: 'chat.reply');
            $this->lastChatOutcome = self::chatOutcome($call);
            if ($this->lastChatOutcome['text'] !== null) {
                return $this->lastChatOutcome['text'];
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
        return $this->caseReplyResult($case, $clientMessage)['text'];
    }

    /**
     * ردّ محادثة القضية **مع تتبّعه** — نظير `replyResult`.
     *
     * @return array{text:?string, meta:array<string,mixed>, source:AiSource}
     */
    public function caseReplyResult(LegalCase $case, string $clientMessage): array
    {
        $nextLabel = $case->nextHearingLabel();
        $next = $nextLabel !== '—' ? "؛ الجلسة القادمة: {$nextLabel}" : '';
        $system = AiPromptRegistry::chatReplySystem()."\n\n"
            ."سياق القضية — رقم: {$case->number}؛ النوع: {$case->type}؛ القسم: {$case->department}؛ الحالة: {$case->status}{$next}. "
            .'أنت تتابع قضية قانونية نشطة لهذا العميل؛ أجب عن استفساراته حول سير القضية والجلسات والإجراءات بدقّة وطمأنة.';

        return self::chatOutcome($this->runCall($system, $this->history($case, $clientMessage), promptId: 'chat.reply'));
    }

    /**
     * حصيلة آخر نداء محادثة — لـ`greet` و`acknowledgeDocs` وحدهما.
     *
     * هاتان تُعيدان **نصّاً دائماً** (لهما احتياطٌ داخليّ)، فلا يمكن تغيير توقيعهما
     * بلا كسر مستدعيهما. والمستدعي يقرأ التتبّع من هنا فوراً بعد النداء —
     * حدٌّ معلَن: لا تُقرأ إلا مباشرةً، ولا يُعوَّل عليها عبر نداءين.
     *
     * @var array{text:?string, meta:array<string,mixed>, source:AiSource}|null
     */
    private ?array $lastChatOutcome = null;

    /**
     * تتبّع آخر ترحيب/إقرار مستندات — يُستهلك مرّةً ثم يُصفَّر.
     *
     * @return array{text:?string, meta:array<string,mixed>, source:AiSource}
     */
    public function takeLastChatOutcome(): array
    {
        $outcome = $this->lastChatOutcome ?? ['text' => null, 'meta' => [], 'source' => AiSource::Fallback];
        $this->lastChatOutcome = null;

        return $outcome;
    }

    /**
     * حصيلة نداء محادثة: نصٌّ ومصدرٌ وتتبّع — مصدرٌ واحد لمداخل المحادثة الأربعة.
     *
     * الردّ الفارغ **ليس نجاحاً**: المستدعي يسقط عندها إلى نصٍّ ثابت، فوسمُه
     * `AiSuccess` كان سيسجّل نجاحاً لمخرجٍ لم يصل العميل منه حرف.
     *
     * @return array{text:?string, meta:array<string,mixed>, source:AiSource}
     */
    private static function chatOutcome(AiCallResult $call): array
    {
        $text = $call->text !== null && trim($call->text) !== '' ? trim($call->text) : null;

        return [
            'text' => $text,
            'meta' => self::callMeta($call, 'chat.reply'),
            'source' => $text !== null ? AiSource::AiSuccess : AiSource::Fallback,
        ];
    }

    /**
     * ملخّص الاستشارة **مع تتبّعه ومصدره**.
     *
     * مخرجٌ مصنَّف `high`، ويحمل «الرأي القانوني» بنصّ تعليمته، ويصل العميل. وكان
     * يُنتَج بلا قيد: لا كلفة ولا نموذج ولا مراجعة مطلوبة.
     *
     * **ولا يُنادى النموذج بلا مادّة.** المُمرَّر إليه خمسة حقول وصفيّة — القناة
     * والمرجع والموضوع واسم المحامي والملاحظات — فحين تخلو الملاحظات لا يبقى إلّا
     * عنوانُ موضوع، والتعليمة تأمره بكتابة «الوقائع ثم الرأي القانوني ثم الإجراءات».
     * فكتب في `CN-2026-4622` (موضوع «عقود المقاولات»، ملاحظات فارغة): «تمّ خلال
     * الاستشارة عرض تفاصيل تعاقدية… تضمّنت بنود العقد، التزامات الأطراف، جدول زمنيّ
     * للتنفيذ، وآلية الدفع» — **محضر جلسة مختلَق** يقرؤه العميل سجلّاً لاستشارته.
     *
     * وهو عين ما وقع للاجتماعات في `M-26753` ومُنِع هناك بهذا الحارس نفسه. وقياسُ
     * `consult.summary` v2 أثبت أن التعليمة وحدها لا تكفي: قلّ الاختلاق ولم ينقطع.
     *
     * @return array{summary:?string, meta:array<string,mixed>, source:AiSource, called:bool}
     */
    public function consultSummaryResult(Consult $consult, string $notes = ''): array
    {
        // مصدر المحتوى الفعليّ الوحيد: ما دوّنه المستشار في الطلب أو ما حُفظ سابقاً
        // في الملفّ. بلا أيّهما لا يُنادى النموذج ولا يُكتب نصّ — و`summary` يبقى
        // `null` لا رسالةَ انتظار: هو حقلُ العميل، وأيّ نصٍّ فيه يصير سلسلةً تُطابَق
        // نصّياً في كل موضع لاحق. ورسالة الانتظار موضعها الواجهة والتنبيه.
        $material = $notes !== '' ? $notes : trim((string) $consult->session_notes);

        if ($material === '') {
            return ['summary' => null, 'meta' => [], 'source' => AiSource::ManualRequired, 'called' => false];
        }

        $notes = $material;
        $system = AiPromptRegistry::consultSummarySystem();
        // **لا اسمَ مستشارٍ في التعليمة**: الملخّص يصل العميل، وما يُعطى للنموذج قد يُعاد في نصّه —
        // والاسم الكامل (`$consult->lawyer`) كان يتسرّب منه. والملخّص عن مضمون الجلسة لا عن شخص.
        $prompt = "استشارة {$consult->channel} رقم {$consult->ref} بموضوع «{$consult->subject}»."
            .($notes !== '' ? "\nملاحظات المستشار أثناء الجلسة:\n{$notes}" : '')
            ."\nاكتب ملخص الاستشارة.";

        $meta = [];

        try {
            $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], promptId: 'consult.summary');
            $meta = self::callMeta($call, 'consult.summary');
            // الحقول النائبة تُرفع خادميّاً: التعليمة v2 تنهى عنها والقياس أظهر أنها
            // تعود («[أدخل التاريخ]») — والعميل يقرأ هذا النصّ في تقريره الرسميّ.
            $text = AiOutputValidator::stripPlaceholders(trim((string) $call->text));
            if ($text !== '') {
                return ['summary' => $text, 'meta' => $meta, 'source' => AiSource::AiSuccess, 'called' => true];
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService consultSummary failed: '.$e->getMessage());
        }

        // عند تعذّر الـAI: لا رأي قانوني مُختلَق يصل للعميل — نصّ أمين بانتظار الإعداد اليدوي
        return [
            'summary' => "ملخص استشارة — {$consult->ref}\n\n"
                ."تعذّر إعداد ملخّص الاستشارة بالذكاء الاصطناعي حالياً. الاستشارة بشأن «{$consult->subject}» "
                .'بحاجة إلى إعداد الملخّص والرأي القانوني يدوياً من الفريق القانوني قبل اعتماده'
                .($notes !== '' ? "، وقد دوّن المستشار أثناء الجلسة: {$notes}" : '').'.',
            'meta' => $meta,
            'source' => AiSource::Fallback,
            'called' => true,
        ];
    }

    /**
     * مخرجات الاجتماع بعد إنهائه: ملخص + محضر + قرارات قابلة للتنفيذ — ذكاء اصطناعي مع احتياط قالبي.
     *
     * @return array{summary: string, minutes: string, decisions: array<int, string>}
     */
    public function meetingSummary(Meeting $meeting, string $notes = ''): array
    {
        return $this->meetingSummaryResult($meeting, $notes)['parts'];
    }

    /**
     * مخرجات الاجتماع **مع تتبّعها**.
     *
     * `meta` فارغة حين لا يُنادى النموذج أصلاً (بلا ملاحظات ولا Zoom) — وقيدٌ بلا نداء
     * يُفسد إحصاء الكلفة، فلا يُكتب هناك.
     *
     * @return array{parts:array{summary:?string,minutes:?string,decisions:array<int,string>}, meta:array<string,mixed>, source:AiSource, called:bool}
     */
    public function meetingSummaryResult(Meeting $meeting, string $notes = ''): array
    {
        // مصدر المحتوى الفعلي الوحيد: الملاحظات المدوَّنة و/أو ملخص Zoom إن سبق وصوله.
        // بلا أيّهما لا يُنادى الذكاء ولا يُكتب أي نصّ: التلخيص من البيانات الوصفية كان يختلق
        // مداولات وقرارات لاجتماع لم يدخله أحد وتُعرض للعميل كمحضر رسمي (حادثة M-26753)،
        // وقرار صاحب المنتج: لا قالب وهمي — الحقول تبقى فارغة حتى يصل ملخص Zoom أو يُدوَّن يدوياً.
        $zoomContent = trim((string) $meeting->zoom_summary);
        if ($notes === '' && $zoomContent === '') {
            return [
                'parts' => self::meetingSummaryFallback($meeting, $notes),
                'meta' => [],
                'source' => AiSource::Fallback,
                'called' => false, // لا نداء ⇒ لا قيد: قيدٌ بلا نداء يُفسد إحصاء الكلفة
            ];
        }

        $system = AiPromptRegistry::meetingSummarySystem();
        $prompt = "عنوان الاجتماع: {$meeting->title}\nالنوع: {$meeting->type}\nالعميل: ".($meeting->client_name ?: 'داخلي')
            .($meeting->case_ref ? "\nمرتبط بـ: {$meeting->case_ref}" : '')
            .($meeting->participants ? "\nالمشاركون: {$meeting->participants}" : '')
            .($notes !== '' ? "\nملاحظات أثناء الاجتماع:\n{$notes}" : '')
            .($zoomContent !== '' ? "\nملخص جلسة Zoom:\n{$zoomContent}" : '')
            ."\nأعد JSON.";

        $meta = [];

        try {
            $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], json: true, promptId: 'meeting.summary');
            $meta = self::callMeta($call, 'meeting.summary');
            $data = self::parseJsonResponse($call->text);
            if (is_array($data) && ! empty($data['summary'])) {
                return [
                    'parts' => [
                        'summary' => (string) $data['summary'],
                        'minutes' => is_array($data['minutes'] ?? null) ? implode("\n", array_map('strval', $data['minutes'])) : (string) ($data['minutes'] ?? ''),
                        'decisions' => array_values(array_filter(array_map('strval', (array) ($data['decisions'] ?? [])))),
                    ],
                    'meta' => $meta,
                    'source' => AiSource::AiSuccess,
                    'called' => true,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService meetingSummary failed: '.$e->getMessage());
        }

        return [
            'parts' => self::meetingSummaryFallback($meeting, $notes),
            'meta' => $meta,
            'source' => AiSource::Fallback,
            'called' => true,
        ];
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
        return $this->extractDecisionsResult($text)['decisions'];
    }

    /**
     * القرارات **مع تتبّعها**.
     *
     * أعلى بوّابة تقييم في المنظومة (0.98) لأن مخرجها يُنشئ التزامات في النظام —
     * وكان يُنتَج بلا قيدٍ يُحصي كلفته أو يُخضعه لمراجعة.
     *
     * @return array{decisions:array<int,string>, meta:array<string,mixed>, source:AiSource, called:bool}
     */
    public function extractDecisionsResult(string $text): array
    {
        if (trim($text) === '') {
            return ['decisions' => [], 'meta' => [], 'source' => AiSource::Fallback, 'called' => false];
        }

        // التعليمة في السجلّ لا هنا: كانت الوحيدة الباقية بلا إصدار ولا بصمة
        $system = AiPromptRegistry::decisionsSystem();
        $meta = [];

        try {
            $call = $this->runCall($system, [['role' => 'user', 'content' => mb_substr($text, 0, 6000)]], json: true, promptId: 'meeting.decisions');
            $meta = self::callMeta($call, 'meeting.decisions');

            // `decisions()` يُفرّق بين «المفتاح غائب» (بنية خاطئة) و«موجود وفارغ»
            // (لا قرارات) — والفارق جوهريّ: نصٌّ بلا قرارات يجب أن يُخرج صفراً.
            $valid = AiOutputValidator::decisions(self::parseJsonResponse($call->text));
            if ($valid !== null) {
                return [
                    'decisions' => $valid['decisions'],
                    'meta' => $meta,
                    'source' => AiSource::AiSuccess,
                    'called' => true,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService extractDecisions failed: '.$e->getMessage());
        }

        // عند تعذّر الـAI: لا مهام أفضل من مهام وهمية — كان الاحتياط يحقن 3 مهام ثابتة كسجلّات حقيقية
        return ['decisions' => [], 'meta' => $meta, 'source' => AiSource::Fallback, 'called' => true];
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
     * الدراسة الذكيّة لطلب التنفيذ (تدفّق البطاقات): ملخّص قانونيّ + نواقص + إجراءات مقترحة
     * + مدخلات التسعير الستّة (v2). يُعيد JSON من الـAI؛ وعند تعذّره يسقط لقالب أمين
     * (فحص المنفَّذ ضده + إجراءات افتراضيّة) **بلا مدخلات تسعير** — القالب لا يقدّر تعقيداً.
     *
     * @return array{summary:string,missing:array<int,string>,procedures:array<int,string>,readiness:string,difficulty:string,expected_procedures_count:int,duration_estimate:string,recovery_indicators:array<int,string>,risks:array<int,string>,source:string}
     */
    public function analyzeExecution(Execution $exec): array
    {
        // قراءة وتلخيص كافة المستندات المرفقة مع الطلب (ومستندات قضيّته/تذكرته المصدر إن وُجدت)
        $exec->loadMissing(['documents', 'legalCase.documents', 'ticket.documents']);
        [$docSnippets, $readableDocs] = $this->documentSnippets(
            $exec->documents->map(fn (ExecutionDocument $d) => ['path' => $d->path, 'label' => (string) $d->label, 'summary' => $d->summary])->all()
        );

        if (empty($docSnippets) && ($ticket = $exec->ticket) !== null && $ticket->documents->isNotEmpty()) {
            [$ticketSnippets, $ticketReadable] = $this->documentSnippets(
                $ticket->documents->map(fn (TicketDocument $d) => [
                    'path' => $d->path,
                    'label' => (string) ($d->name ?: ($d->doc_type ?: 'مستند')),
                    'summary' => $d->summary,
                ])->all()
            );
            $docSnippets = $ticketSnippets;
            $readableDocs += $ticketReadable;
        }

        $docsContext = ! empty($docSnippets)
            ? "\n\nالمستندات المرفقة مع الطلب:\n".implode("\n", $docSnippets)
            : "\n(لم يتم إرفاق مستندات بعد)";

        /*
         * **مستندات القضيّة المصدر سياقُ دراسةٍ لا نسخةٌ ثانية.**
         *
         * التنفيذ المفتوح من قضيّة (`ExecutionCreation::fromCase`) يصل بلا مستندٍ واحد،
         * فتُدرَس مطالبةٌ بلا صكّ حكمٍ ولا صحيفة دعوى. والبديل — إنشاء صفوف
         * `ExecutionDocument` تشير إلى **مسارات ملفّات القضيّة نفسها** — فخّ: الحذف عبر
         * `PurgesDocumentFiles`/`PurgesStoredFile` يمحو الملفّ من القرص، فحذفُ ملفّ تنفيذ
         * يمحو صكّ الحكم من القضيّة. ونسخُ الملفّات يضاعف التخزين ويُنشئ نسختين تتباعدان.
         * فالمستندات تُقرأ سياقاً هنا، وأسماؤها تُعرض في `docs` على البطاقة.
         */
        $caseDocsContext = '';
        if (($case = $exec->legalCase) !== null) {
            [$caseSnippets, $caseReadable] = $this->documentSnippets(
                $case->documents->map(fn (CaseDocument $d) => [
                    'path' => $d->path,
                    'label' => (string) ($d->doc_type ?: $d->name),
                    'summary' => $d->summary,
                ])->all()
            );
            $readableDocs += $caseReadable;
            $caseDocsContext = ! empty($caseSnippets)
                ? "\n\nمستندات القضيّة {$case->number} التي صدر فيها الحكم (سياقٌ للدراسة):\n".implode("\n", $caseSnippets)
                : '';
        }

        $system = AiPromptRegistry::executionAnalyzeSystem();

        // تمويه ما كتبه العميل قبل مغادرة الخادم. كان **نصّ المستندات وحده** يُموَّه،
        // بينما «الملاحظات» و«الموضوع» و«المنفَّذ ضده» تُرسَل خاماً — وهي حقولٌ حرّة
        // يكتبها العميل ويضع فيها رقم هويّته وجوّاله عادةً. أثبته دليل التدقيق نفسه:
        // طلبٌ حقيقيّ فيه «هوية 1055667788 · جوال 0553334444» سجّل `masked = []`.
        $prompt = "طلب تنفيذ {$exec->number} — نوع السند: {$exec->sanad}، "
            .'الموضوع: «'.AiContextBuilder::prepare((string) $exec->subject).'»، '
            .'قيمة المطالبة: '.number_format((int) $exec->amount).' ريال، '
            .'المنفَّذ ضده: '.($exec->defendant ? AiContextBuilder::prepare((string) $exec->defendant) : 'غير محدّد').'، '
            .'ملاحظات: '.($exec->notes ? AiContextBuilder::prepare((string) $exec->notes) : '—').'.'
            .$docsContext
            .$caseDocsContext
            ."\n\nحلّل الطلب والمستندات المرفقة وأعد JSON.";

        $call = new AiCallResult(text: null, traceId: (string) Str::uuid(), durationMs: 0, failureCode: AiFailure::PROVIDER_ERROR);
        $structureFailure = null;

        try {
            $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], true, promptId: 'execution.analyze');
            if ($call->succeeded()) {
                // التحقّق الخادميّ المستقلّ: لا يُوثَق بأن النموذج اتّبع التعليمات
                $data = self::parseJsonResponse($call->text);
                $structureFailure = $data === null ? AiFailure::INVALID_JSON : AiFailure::INVALID_STRUCTURE;
                $valid = AiOutputValidator::executionAnalysis($data);
                if ($valid !== null) {
                    return $valid + [
                        'source' => AiSource::AiSuccess->value,
                        'meta' => self::callMeta($call, 'execution.analyze', null, AiConfidence::forExecution(
                            summary: $valid['summary'],
                            // مستندات القضيّة تُعدّ في الثقة كما تُقرأ في السياق: ملفٌّ مفتوحٌ
                            // من قضيّةٍ يصل بلا مرفقٍ خاصّ به، فعدّ مرفقاته وحدها يخفض ثقة
                            // دراسةٍ قرأت صكّ الحكم فعلاً.
                            documentsTotal: $exec->documents->count() + (int) $exec->legalCase?->documents->count() + ($exec->documents->isEmpty() ? (int) $exec->ticket?->documents->count() : 0),
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
        // **لا قالب يملأ فراغ الذكاء** (قرار المالك 2026-09-12). كان هذا الفرع يكتب حكماً
        // قانونيّاً — «سندٌ … مؤهّل للإحالة ومباشرة الإجراءات» — بلا قراءة مستندٍ واحد، ويضيف
        // ثلاثة إجراءات مثبّتة. فيقرأ المسعِّر تقديراً لم يقع. التعذّر يبقى تعذّراً، وتُعاد
        // جدولة الدراسة (`AnalyzeExecutionJob::failed` و`exec:retry-study`).
        // و«النواقص» تبقى: نقصُ بيانات المنفَّذ ضده حقيقةٌ في سجلّنا لا حكمٌ على مستند.
        $missing = trim((string) $exec->defendant) === '' ? ['بيانات المنفَّذ ضده'] : [];

        return [
            'summary' => '',
            'missing' => $missing,
            'procedures' => [],
            // **مدخلات التسعير فارغةٌ في الاحتياطيّ.** جاهزيّةُ سندٍ ودرجةُ تعقيدٍ ومؤشّراتُ
            // تحصيلٍ أحكامٌ على مستندات، وهذا الفرع لم يقرأ مستنداً واحداً — فقيمةٌ هنا
            // تكون مخترَعةً يسعّر عليها المحامي. الفراغ معلومةٌ صادقة: «لا دراسة بعد».
            'readiness' => '',
            'difficulty' => '',
            'expected_procedures_count' => 0,
            'duration_estimate' => '',
            'recovery_indicators' => [],
            'risks' => [],
            'source' => AiSource::Fallback->value,
            'meta' => self::callMeta($call, 'execution.analyze', $structureFailure),
        ];
    }

    /**
     * مقتطفات مستندات للتحليل — مصدرٌ واحد لمرفقات الطلب ولمستندات القضيّة المصدر.
     * كانت الحلقة مكتوبةً داخل `analyzeExecution`، فقراءةُ مستندات القضيّة كانت ستنسخها.
     *
     * @param  array<int, array{path:?string, label:string, summary:?string}>  $docs
     * @return array{0:array<int,string>, 1:int} المقتطفات، وعددُ ما أمكن قراءة محتواه فعلاً
     */
    private function documentSnippets(array $docs): array
    {
        $snippets = [];
        $readable = 0; // ما أمكن استخراج محتواه فعلاً — إشارة ثقة موضوعيّة

        foreach ($docs as $doc) {
            $path = (string) ($doc['path'] ?? '');
            $abs = $path !== '' ? Storage::disk('local')->path($path) : '';
            $content = '';

            if ($abs !== '' && is_file($abs)) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($text = $this->extractText($abs, $ext)) {
                    $content = AiContextBuilder::prepare($text, 3000);
                } elseif (! empty($doc['summary'])) {
                    $content = (string) $doc['summary'];
                }
            } elseif (! empty($doc['summary'])) {
                $content = (string) $doc['summary'];
            }

            $readable += $content !== '' ? 1 : 0;
            $snippets[] = '- ملف: '.basename($path)." (التصنيف: {$doc['label']})".($content ? " — محتواه:\n{$content}" : '');
        }

        return [$snippets, $readable];
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

        $call = new AiCallResult(text: null, traceId: (string) Str::uuid(), durationMs: 0, failureCode: AiFailure::PROVIDER_ERROR);

        $fileName = basename((string) $doc->path);
        $system = AiPromptRegistry::withOffice('أنت مساعد قانوني في قسم التنفيذ في «{office}» بالسعودية. اقرأ محتوى المستند المرفق على طلب تنفيذ كاملاً، ')
            .'صنّف نوعه، ولخّص محتواه موضحاً مدى توافقه مع بيانات الطلب (نوع السند/قيمة المطالبة/المنفَّذ ضده) إن أمكن. أعد JSON فقط: '
            .'{"doc_type":"نوع المستند كما فهمته من محتواه","summary":"ملخّص محتوى المستند وتوافقه مع الطلب في سطر أو سطرين"}. لا نص خارج JSON.';
        $context = "طلب تنفيذ {$exec->number} — نوع السند: {$exec->sanad}، الموضوع: «{$exec->subject}»، "
            .'قيمة المطالبة: '.number_format((int) $exec->amount).' ريال، '
            .'المنفَّذ ضده: '.($exec->defendant ?: 'غير محدّد')."\nاسم الملف: {$fileName}";

        try {
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $json = null;

            if ($text = $this->extractText($abs, $ext)) {
                $prompt = $context."\n\nمحتوى المستند:\n".AiContextBuilder::prepare($text, 20000)."\n\nلخّص المحتوى وصنّفه وأعد JSON.";
                // `runCall` لا `run` — فحص مستند التنفيذ كان يجري بلا قيدٍ في `ai_runs`
                // أيضاً، فلا يظهر في الكلفة ولا التغطية ولا صندوق المراجعة.
                $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], json: true, promptId: 'document.analyze');
                $json = $call->text;
            } elseif (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true) && ! empty(config('services.gemini.key'))) {
                $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext];
                $json = $this->viaGeminiDocument($system, $context."\n\nلخّص المستند المرفق وصنّفه وأعد JSON.", base64_encode((string) file_get_contents($abs)), $mime);
            }

            if ($json && ($data = self::parseJsonResponse($json)) && (isset($data['summary']) || isset($data['doc_type']))) {
                return [
                    'doc_type' => (string) ($data['doc_type'] ?? 'مستند'),
                    'summary' => (string) ($data['summary'] ?? ''),
                    'meta' => self::callMeta($call, 'document.analyze'),
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
        $system = AiPromptRegistry::withOffice('أنت منسّق إسناد في «{office}». اختر المحامي الأنسب تخصّصاً لموضوع التذكرة من القائمة. ')
            .'أعد JSON فقط: {"lawyer_id": المعرّف الرقمي للمحامي المختار}. لا نص خارج JSON.';
        $prompt = "نوع التذكرة: {$ticket->type}\nالقسم: ".($ticket->department ?: '—')."\n\nالمحامون المتاحون:\n{$list}";

        try {
            $json = $this->run($system, [['role' => 'user', 'content' => $prompt]], json: true, promptId: 'lawyer.match');
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
     * أنواع المساعدة التي يُمرَّر لها سند نظاميّ.
     *
     * وُسِّعت لتشمل `reply_memo` و`defense` و`strengths_weaknesses` و`mems`: تعليماتها
     * تطلب «الأسانيد… نظام المعاملات المدنية، نظام الإثبات، نظام المرافعات» صراحةً،
     * فحرمانها من المصادر لا يمنع الاستشهاد — يجعله من ذاكرة النموذج وحدها.
     *
     * والتوسيع آمنٌ على الغطاء: قاعدة المصادر مجالان (721 عامّة و65 للتنفيذ)،
     * وفلتر `retrieve` يقبل «المجال **أو** العامّ» — فكل قسمٍ يصله الـ721.
     *
     * @var array<int,string>
     */
    private const RETRIEVAL_KINDS = ['qualification', 'contract_check', 'analyze', 'lawahe', 'reply_memo', 'defense', 'strengths_weaknesses', 'mems'];

    /**
     * المساعد القانوني الذكي للمحامي — يولد اللوائح، مذكرات الرد، فحص العقود،
     * استخراج نقاط القوة والضعف، وتكييف النزاع وفق الأنظمة السعودية.
     */
    public function assist(string $kind, string $docType, ?string $ref = null, string $context = ''): string
    {
        return $this->assistResult($kind, $docType, $ref, $context)['draft'];
    }

    /**
     * مسودّة المساعد **مع تتبّع النداء ومصدرها**.
     *
     * كانت `assist()` تُرجع نصّاً وحده، فيستلم المحامي مخرجَ النموذج ومخرجَ القالب
     * بالشكل نفسه تماماً — والقالب فيه «(م/191)» و«العقد شريعة المتعاقدين» و«مهلة
     * إخطار كتابي (15 يوماً)» و«المحكمة المختصة بمدينة الرياض»، وتحته في الشاشة
     * «مستندة للأنظمة والقضاء السعودي». ولا قيد في `ai_runs` رغم حساسيّتها `high`.
     *
     * @return array{draft:string, meta:array<string,mixed>, source:AiSource}
     */
    public function assistResult(string $kind, string $docType, ?string $ref = null, string $context = ''): array
    {
        $refText = $ref ? "المرجع: {$ref}\n" : '';
        $refContext = '';
        // يُحتفظ به خارج الشرط: منه يُشتقّ مجال الاسترجاع أدناه
        $ticket = null;
        $refFacts = '';

        if ($ref) {
            $ticket = Ticket::where('number', $ref)->with(['summary', 'documents'])->first();
            if ($ticket) {
                $refContext = "بيانات التذكرة: {$ticket->type} — {$ticket->subject}\nتفاصيل: {$ticket->details}\n";
                if ($ticket->summary) {
                    $refContext .= "ملخص الوقائع: {$ticket->summary->facts}\nالتوصيات: {$ticket->summary->key_points}\n";
                }
                // **المضمون بلا ترويسات** لاستعلام الاسترجاع — انظر تعليل بنائه أدناه
                $refFacts = implode(' ', array_filter([
                    (string) $ticket->type,
                    (string) $ticket->subject,
                    (string) $ticket->details,
                    (string) ($ticket->summary?->facts ?? ''),
                    (string) ($ticket->summary?->key_points ?? ''),
                ]));
            }
        }

        $fullContext = trim($refText.$refContext."\nالسياق والمستندات:\n".$context);

        /** @var array<int,string> معرّفات المصادر المُمرَّرة — فارغة حين لا استرجاع لهذا النوع */
        $sourceRefs = [];

        // المسارات التي يُمرَّر لها سند — انظر `RETRIEVAL_KINDS`.
        if (in_array($kind, self::RETRIEVAL_KINDS, true)) {
            // ⚠️ **المجال والاستعلام كلاهما كان معطَّلاً.**
            //
            // `domain: ''` يُسقط فلتر المجال بالكامل، فتُمرَّر موادّ من مجالٍ أجنبيّ
            // تحت لافتة «معتمدة، استشهد بمعرّفاتها حصراً» — وهو العطل نفسه المعالَج
            // داخل `retrieve` وعائدٌ من باب المستدعي.
            //
            // و`query: $docType` أشدّ: سطرٌ كـ«مذكرة رد» لا يُنتج كلمةً مفتاحيّة
            // تطابق نصّاً نظامياً — قيس حيّاً: «مذكرة رد» ⇒ **صفر مصدر**، ومع وقائع
            // الملفّ ⇒ ستّة. أي أن الاسترجاع كان معطَّلاً عملياً لا ناقصاً.
            //
            // والاستعلام من الملفّ هو نمط `draftPleadingResult` نفسه (وقائع لا نوع).
            $retrieved = LegalKnowledge::retrieve(
                domain: (string) ($ticket?->department ?: ($ticket?->type ?: '')),
                // ⚠️ **المضمون لا الكتلة المُنسّقة.** بناء الاستعلام من $refContext
                // يُدخل ترويساته («بيانات التذكرة» · «تفاصيل» · «السياق والمستندات»)،
                // وهي كلماتٌ طويلة تتصدّر الانتقاء بالطول فتُزيح المصطلحات القانونيّة.
                // قيس: بالترويسات ⇒ [مستندات، بيانات، تفاصيل، مطالبة، تذكرة، تجاري]،
                // وبلا ترويسات ⇒ [مطالبة، تجاري، توريد، تسليم، بضاعة، تعويض].
                // أربعةٌ من ستّة كانت حشواً — وهو عطل الحشو نفسه الموثَّق في `keywords`.
                query: trim($docType.' '.AiContextBuilder::clip($refFacts.' '.$context, 2000)),
            );
            $sourceRefs = $retrieved->pluck('ref')->map(fn ($r) => (string) $r)->all();
            $authority = LegalKnowledge::asContext($retrieved);

            if ($authority !== '') {
                $fullContext .= "\n\nمصادر نظاميّة معتمدة (استشهد بمعرّفاتها حصراً، ولا تذكر مادّة خارجها):\n".$authority;
            }
        }

        // خمس تعليمات تُنتقى بـ$kind — انتقلت إلى AiPromptRegistry مع تجميد بصماتها
        $system = AiPromptRegistry::assistantDraftSystem($kind, $docType);

        $prompt = "نوع المستند المطلوب: {$docType}\n\n{$fullContext}\n\nيرجى إعداد المسودة باحترافية عالية وبلفظ قانوني سعودي رصين.";

        $meta = [];

        try {
            $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], json: false, promptId: 'assistant.draft');
            $meta = self::callMeta($call, 'assistant.draft');
            if ($call->text && trim($call->text) !== '') {
                return [
                    // الاستشهادات تُطابَق خادميّاً قبل أن تصل المحامي — انظر أدناه
                    'draft' => self::flagUnmatchedCitations(trim($call->text), $sourceRefs),
                    'meta' => $meta,
                    'source' => AiSource::AiSuccess,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService assist failed: '.$e->getMessage());
        }

        // احتياط منظم عند تعذّر النموذج — **موسوم** كي لا يُقرأ تحليلاً
        return [
            'draft' => $this->fallbackAssistantDraft($kind, $docType, $ref, $context),
            'meta' => $meta,
            'source' => AiSource::Fallback,
        ];
    }

    /**
     * توليد مسودة صحيفة دعوى / لائحة مطابقة لمعايير منصة «ناجز».
     *
     * غلافٌ نصّيّ فوق `najizStatementResult` — للمستدعين الذين لا يحتاجون التتبّع.
     */
    public function generateNajizDraft(Ticket $ticket): string
    {
        return $this->najizStatementResult($ticket)['draft'];
    }

    /**
     * الصحيفة **مع حصيلة الاستشهاد وتتبّع النداء**.
     *
     * كانت تُنتَج نصّاً حرّاً: بلا `promptId` فبلا إصدارٍ في السجلّ، وبلا قيدٍ في
     * `ai_runs` أصلاً (قيس: 22 قيداً قبل النداء و22 بعده)، وبلا استرجاعٍ فتُذكر
     * الموادّ من ذاكرة النموذج. وهي **تُقدَّم للمحكمة**.
     *
     * @return array{draft:string, meta:array<string,mixed>, source:AiSource, verdict:?string}
     */
    public function najizStatementResult(Ticket $ticket): array
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

        // استرجاع قانونيّ موثَّق — كان هذا المسار **بلا استرجاع أصلاً**، والتعليمة
        // تطلب من النموذج «ذكر نصوص المواد»، فيستدعيها من ذاكرته. قيس حيّاً: صحيفة
        // من 5541 حرفاً فيها ستّ موادّ بأرقامها ولا معرّف مصدرٍ واحد يقابله صفّ.
        // وهذه صحيفةٌ تُقدَّم للمحكمة.
        $sources = LegalKnowledge::retrieve(
            domain: (string) ($ticket->department ?: $ticket->type),
            query: trim(implode(' ', array_filter([
                (string) $ticket->subject,
                (string) ($summary?->facts ?? ''),
                (string) ($summary?->key_points ?? ''),
            ]))),
        );
        $authority = LegalKnowledge::asContext($sources);
        if ($authority !== '') {
            $context .= "\n\nمصادر نظاميّة معتمدة (استشهد بمعرّفاتها حصراً، ولا تذكر مادّة خارجها):\n".$authority;
        }

        $system = AiPromptRegistry::najizStatementSystem();
        $meta = [];
        $prompt = "بيانات ملف التذكرة:\n{$context}\n\nصغ صحيفة الدعوى لمعايير ناجز بشكل نهائي وجاهز للمراجعة.";

        try {
            $call = $this->runCall($system, [['role' => 'user', 'content' => $prompt]], json: true, promptId: 'najiz.statement');
            $meta = self::callMeta($call, 'najiz.statement');

            // الحكم للخادم: معرّفٌ لا يقابله صفٌّ معتمد يسقط إلى «غير مدعوم»
            $validated = LegalClaims::validate(
                self::parseJsonResponse($call->text),
                LegalKnowledge::existingRefs($sources->pluck('ref')->all()),
            );

            if ($validated !== null) {
                return [
                    'draft' => $this->renderPleading((string) $ticket->number, $validated, 'صحيفة دعوى', self::citationMap($sources)),
                    'meta' => $meta,
                    'source' => LegalClaims::isCitable($validated)
                        ? AiSource::AiSuccess
                        : AiSource::ManualRequired,
                    'verdict' => $validated['verdict'],
                ];
            }

            if ($call->text && trim($call->text) !== '') {
                // لم يجتز التحقّق البرمجيّ ⇒ ليس نجاحاً، ويلزمه مراجع
                return [
                    'draft' => trim($call->text),
                    'meta' => $meta,
                    'source' => AiSource::ManualRequired,
                    'verdict' => null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('LegalAiService generateNajizDraft failed: '.$e->getMessage());
        }

        // مسودة ناجز احتياطية منظمة
        return ['draft' => "المملكة العربية السعودية\nوزارة العدل — منصة ناجز الإلكترونية\nصحيفة دعوى إلكترونية\n\n"
            .'لدى المحكمة المختصة بمدينة: '.SettingsRegistry::str('office_city')."\n\n"
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
            // توقيع الوكيل باسم المكتب من الإعدادات — مسودّةٌ تُرفع للمحكمة لا تحمل اسماً منقوشاً قديماً
            ."وتفضلوا بقبول وافر الاحترام والتقدير،،\nوكيل المدعي / ".SettingsRegistry::str('office_name'),
            'meta' => $meta ?? [],
            'source' => AiSource::Fallback,
            'verdict' => null,
        ];
    }

    /**
     * وسمُ الاستشهادات التي لا يقابلها مصدرٌ مُمرَّر — في مسودّة المساعد القانونيّ.
     *
     * **العلّة:** التعليمة تُحقن بمصادر معتمدة وتأمر «استشهد بمعرّفاتها حصراً، ولا
     * تذكر مادّة خارجها»، ثم **لا يُطابَق شيء**. و`LegalClaims::validate` تُنادى في
     * `case.pleading` و`najiz.statement` وحدهما، لأنهما تُعيدان JSON بادّعاءاتٍ
     * مبنيّة. أمّا هذه فتُعيد نصّاً حرّاً، فلا بنية تُطابَق.
     *
     * فالمطابقة هنا على ما يُذكر في النصّ نفسه: كل معرّف `[LS-…]` يُقارَن بالمُمرَّر،
     * وكل «المادة (N)» بلا معرّفٍ بجوارها استشهادٌ من ذاكرة النموذج. والنتيجة
     * **تُوسَم ولا تُحذف**: القارئ محامٍ، وحذفُ نصٍّ من مذكّرته أشدّ من تنبيهه.
     * (ولهذا يختلف عن `stripPlaceholders` التي تحذف: قارئها العميل.)
     *
     * @param  array<int,string>  $sourceRefs  معرّفات المصادر المعتمدة المُمرَّرة
     */
    private static function flagUnmatchedCitations(string $draft, array $sourceRefs): string
    {
        // معرّفات ذُكرت في النصّ ولا وجود لها في المُمرَّر — اختلاقُ معرّف
        preg_match_all('/\[(LS-[A-Z0-9\-]+)\]/u', $draft, $cited);
        $invented = array_values(array_unique(array_diff($cited[1] ?? [], $sourceRefs)));

        // أرقام موادّ بلا أيّ معرّف مصدرٍ في النصّ — استشهادٌ من الذاكرة
        // بلا أداة تعريف ملزمة: «للمادة» و«وفق مادة» لا تحملان «الماد» حرفيّاً
        preg_match_all('/ماد[ةه]\s*\(?\s*[\d٠-٩]+/u', $draft, $articles);
        $bare = ($cited[1] ?? []) === [] ? count($articles[0] ?? []) : 0;

        if ($invented === [] && $bare === 0) {
            return $draft;
        }

        $notes = [];
        if ($invented !== []) {
            $notes[] = 'معرّفات لا تقابل مصدراً معتمداً: '.implode('، ', $invented);
        }
        if ($bare > 0) {
            $notes[] = "ذُكرت {$bare} مادّة بلا معرّف مصدرٍ واحد";
        }

        return "⚠️ استشهادات غير مُطابَقة — تحقّق منها قبل الاعتماد.\n"
            .'('.implode(' · ', $notes).")\n\n".$draft;
    }

    /** مسودة احتياطية منظمة للمساعد القانوني */
    /** وسمُ القالب — **فوق النصّ** كما في `renderPleading`، لا في حقلٍ جانبيّ يُتجاوَز. */
    private const TEMPLATE_BANNER = "⚠️ قالب استرشاديّ ثابت — لم يُجرَ تحليل.\n"
        ."الموادّ والمُهَل والاختصاص الواردة أدناه **أمثلةٌ لا أسانيد**؛ تحقّق منها قبل الاستعمال.\n\n";

    private function fallbackAssistantDraft(string $kind, string $docType, ?string $ref, string $context): string
    {
        $refHeader = $ref ? "المرجع القضائي: {$ref}\n\n" : '';

        // القوالب الخمسة تحمل ادّعاءات نظاميّة صريحة: «(م/191)» و«العقد شريعة
        // المتعاقدين» و«البينة على المدعي» و«مهلة إخطار كتابي (15 يوماً)» و«المحكمة
        // المختصة بمدينة الرياض». وكان المحامي يستلمها كما يستلم مخرج النموذج تماماً.
        // لا تُحذف — قيمتها الاسترشاديّة قائمة — لكنها تُعرّف نفسها قبل أن تُقرأ.
        return self::TEMPLATE_BANNER.match ($kind) {
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
                .'• الاختصاص القضائي: يُوصى بتحديد المحكمة المختصة بمدينة '.SettingsRegistry::str('office_city')." صراحةً.\n\n"
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

        // ⚠️ التمويه **عند الحدّ** لا عند كل موضع استدعاء.
        //
        // كان كل مسار يُموّه سياقه بنفسه، فتكرّر العطل نفسه ثمانيَ مرّات: الفرز موّه،
        // واللائحة لم تموّه، وتحليل التنفيذ موّه مستنداته ولا موّه ملاحظات العميل،
        // وثلاث دوالّ للمستندات أرسلت نصّ العقد خاماً بعشرين ألف حرف. الجامع أن
        // الحماية كانت تُطبَّق حيث تذكّرها كاتبها، ودالّةٌ جديدة تنسى فتُسرّب صامتةً.
        //
        // هنا آخر موضع يمرّ به النصّ قبل المزوّد، فالتمويه فيه يجعل التسريب **غير
        // ممكن بنيوياً**: لا يلزم مستدعياً أن يتذكّر. والتمويه لا يُطبَّق مرّتين
        // بأثرٍ ضارّ — العلامة `[هوية]` لا تطابق نمط الهويّة، فإعادته لا تُفسد شيئاً.
        //
        // وتُبقى `prepare` في مواضعها: تُنظّف الوسوم وتقتطع الحجم، وذانك شأنُ مصدرٍ
        // يعرف بنية نصّه، لا شأن الحدّ.
        $messages = array_map(
            fn (array $m) => ['role' => $m['role'], 'content' => AiContextBuilder::mask((string) $m['content'])],
            $messages
        );

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
        }, promptId: $promptId)->withOutboundAudit($audit);
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
            'max_tokens' => self::maxTokensFor($promptId),
            'temperature' => $json ? 0.3 : 0.7,
        ];
        if ($json) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $base = rtrim((string) config('services.glm.base', 'https://api.z.ai/api/paas/v4'), '/');
        $response = Http::timeout(self::timeoutFor($promptId, 30))
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
            'maxOutputTokens' => self::maxTokensFor($promptId),
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
        $response = Http::timeout(self::timeoutFor($promptId, 40))
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
        // **مفتاح الإطفاء والميزانيّة هنا أيضاً.** هذا المسار خارج البوّابة، فكان إطفاءُ `document.analyze`
        // من لوحة التشغيل يوقف فحص النصوص وحدها ويترك PDF والصور تُرسَل — والفحص صار يحكم باستيفاء
        // قائمة المستندات، فإطفاؤه يجب أن يعني «لا حكم آليّ» لكلّ ملف. (نداءاته الثلاثة كلّها `document.analyze`.)
        if (! Setting::aiTaskEnabled('document.analyze') || AiOpsMetrics::budgetStopsCalls()) {
            return null;
        }

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
