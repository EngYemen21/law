<?php

namespace App\Services\Ai;

use App\Models\AiEvaluationRun;
use App\Models\Setting;
use App\Services\LegalAiService;

/**
 * الطبقة الثانية من التقييم: تشغيل الحالات المجهّلة على **مزوّد حيّ** ومقارنة
 * مخرجاته بالنتائج المرجعيّة.
 *
 * الطبقة الأولى (`AiEvaluationTest`) تقيس **طبقاتنا** أمام مخرجات نموذج مثبَّتة —
 * لا تقيس النموذج. وهذه تقيس النموذج نفسه، وبدونها لا يُعرف أثر تغيير تعليمة أو
 * ترقية نموذج إلّا بعد وقوعه على ملفّات عملاء حقيقيّة.
 *
 * **الحالات مصطنعة بالكامل** (`tests/Fixtures/ai/`) — لا بيانات عملاء تُرسَل، وهو
 * شرط صريح في تعليمات الخطة.
 */
class AiEvaluator
{
    /**
     * بوّابات العبور من الخطة — تُعاير مع الفريق القانونيّ لاحقاً.
     *
     * الأرقام ليست متساوية عمداً: `meeting.decisions` تُنشئ مهامّ في النظام، فاختلاق
     * قرارٍ واحد يُنشئ التزاماً لم يقرّره أحد — ولذلك أعلاها. والملخّص والاستشهاد
     * يُعرضان على المحامي بوصفهما وقائع وسنداً، فهما فوق التصنيف والفرز.
     */
    public const GATES = [
        'ticket.triage' => 0.90,
        'document.analyze' => 0.90,
        'consult.analyze' => 0.90,
        'execution.analyze' => 0.90,
        'ticket.summary' => 0.95,
        'case.pleading' => 0.95,
        'meeting.decisions' => 0.98,
    ];

    /**
     * ما يمكن تشغيله حيّاً. حالات `execution.analyze` تصف **إشارات** (عدد المستندات،
     * وجود سند) لا نصّاً يُرسَل لنموذج، فلا مدخل حيّاً لها. تُعلَن جافّة صراحةً بدل
     * أن يُوهم التقرير بأنها اختُبرت على النموذج.
     */
    public const LIVE_CAPABLE = ['ticket.triage', 'consult.analyze', 'document.analyze', 'meeting.decisions'];

    /** آخر نتيجة تقييم محفوظة — تعرضها اللوحة. */
    public const SETTING_KEY = 'ai_last_evaluation';

    private float $cost = 0.0;

    /** هل عرفنا كلفة كل نداء؟ سعرٌ مجهول واحد يجعل المجموع جزئياً لا نهائياً. */
    private bool $costComplete = true;

    private int $liveCalls = 0;

    public function __construct(private ?LegalAiService $ai = null) {}

    /**
     * يشغّل مجموعات الحالات ويعيد حصيلة كل مهمّة.
     *
     * @param  array<int,string>  $tasks  معرّفات المهام؛ فارغة = كلّها
     * @param  bool  $live  نداء حقيقيّ للمزوّد (يستهلك حصّة) أم مقارنة بالمخرجات المثبَّتة
     * @return array<int,array<string,mixed>>
     */
    public function run(array $tasks = [], bool $live = false): array
    {
        $results = [];

        // بترتيب البوّابات لا بترتيب أسماء الملفّات: تقريرٌ يتبدّل ترتيبه بإعادة تسمية
        // ملفّ لا يُقارَن بسابقه، والمقارنة هي الغرض كلّه
        $sets = array_column(self::fixtures(), null, 'task');

        foreach (array_keys(self::GATES) as $task) {
            $set = $sets[$task] ?? null;
            if ($set === null || ($tasks !== [] && ! in_array($task, $tasks, true))) {
                continue;
            }

            $liveHere = $live && in_array($task, self::LIVE_CAPABLE, true);
            $results[] = $this->runSet($set, $liveHere) + [
                'live' => $liveHere,
                // السبب معلن: «لم يُشغَّل حيّاً» بلا سبب يُقرأ كإهمال
                'liveSkipped' => $live && ! $liveHere
                    ? 'حالات هذه المهمّة تصف إشارات لا نصّاً يُرسَل لنموذج'
                    : null,
            ];
        }

        return $results;
    }

    /**
     * حصيلة مجموعة واحدة.
     *
     * @return array{task:string,total:int,passed:int,rate:float,gate:float,meets:bool,failures:array<int,string>}
     */
    public function runSet(array $set, bool $live = false): array
    {
        $task = (string) $set['task'];
        $roster = (array) ($set['roster'] ?? []);
        $refs = (array) ($set['existing_refs'] ?? []);
        $failures = [];
        $passed = 0;

        $skipped = 0;

        foreach ((array) $set['cases'] as $case) {
            // حالةٌ توقُّعها معلَّق بحكم النموذج نفسه (اختلاق، إغفال، انسياق خلف حقن)
            // لا تُقاس على مخرجٍ مثبَّت: المحقِّق لا يرى النصّ الأصليّ فلا سبيل له إلى
            // كشف ما زاده النموذج عليه. عدُّها ساقطةً في الوضع الجافّ يجعل البوّابة
            // غير قابلة للعبور أبداً، فيتحوّل التحذير إلى ضجيج يُتجاهَل.
            if (! $live && ($case['live_only'] ?? false)) {
                $skipped++;

                continue;
            }

            $output = $live
                ? $this->callProvider($task, (array) $case['input'], $roster)
                : ($case['model_output'] ?? null);

            $valid = self::validate($task, $output, $roster, $refs);
            $expect = (array) $case['expect'];
            $id = (string) $case['id'];

            if ((bool) ($expect['valid'] ?? true) !== ($valid !== null)) {
                $failures[] = $valid === null
                    ? "{$id}: لم يُنتج مخرجاً صالحاً وكان مرجعه صالحاً"
                    : "{$id}: أنتج مخرجاً صالحاً وكان مرجعه ساقطاً";

                continue;
            }

            if ($valid === null) {
                $passed++;

                continue;
            }

            $mismatch = self::firstMismatch($valid, $expect);
            if ($mismatch !== null) {
                $failures[] = "{$id}: {$mismatch}";

                continue;
            }

            $passed++;
        }

        // المقام ما قِيس فعلاً لا ما وُجد في الملفّ — نسبةٌ مقامها حالاتٌ لم تُشغَّل كذبة
        $total = count((array) $set['cases']) - $skipped;
        $rate = $total > 0 ? round($passed / $total, 3) : 0.0;
        $gate = self::GATES[$task] ?? 0.90;

        return [
            'task' => $task,
            'total' => $total,
            'passed' => $passed,
            'skipped' => $skipped,
            'rate' => $rate,
            'gate' => $gate,
            'meets' => $total > 0 && $rate >= $gate,
            'failures' => $failures,
        ];
    }

    /** كلفة التشغيل الحيّ، أو `null` إن جهلنا سعر نموذجٍ نودي — لا صفر يوهم بالمجّانيّة. */
    public function cost(): ?float
    {
        if ($this->liveCalls === 0) {
            return null;
        }

        return $this->costComplete ? round($this->cost, 4) : null;
    }

    public function liveCalls(): int
    {
        return $this->liveCalls;
    }

    /** @return array<int,array<string,mixed>> */
    public static function fixtures(): array
    {
        $sets = [];
        foreach (glob(base_path('tests/Fixtures/ai/*.json')) ?: [] as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) && isset($data['task'], $data['cases'])) {
                $sets[] = $data;
            }
        }

        return $sets;
    }

    /** يحفظ آخر تقييم كي تعرضه اللوحة — نتيجةٌ بلا تاريخ لا تُقارَن بشيء. */
    public static function remember(array $results, bool $live, ?float $cost, ?string $by = null, ?string $failure = null, int $liveCalls = 0): void
    {
        Setting::put(self::SETTING_KEY, json_encode([
            'at' => now()->toDateTimeString(),
            'live' => $live,
            'cost' => $cost,
            'by' => $by,
            'failure' => $failure,
            'running' => false,
            'results' => $results,
        ], JSON_UNESCAPED_UNICODE));

        // خطّ الأساس: قيدٌ دائم لكل تشغيل مكتمل. المفتاح أعلاه حالةٌ راهنة يُستبدَل،
        // وبه وحده لا يبقى ما يُقارَن به — فتتراجع مهمّة ولا يُلاحَظ.
        // التشغيل الساقط لا يُقيَّد: حصيلته ليست قياساً بل خبرُ تعذُّر.
        if ($failure !== null || $results === []) {
            return;
        }

        AiEvaluationRun::create([
            'live' => $live,
            'triggered_by' => $by,
            'cost' => $cost,
            'live_calls' => $liveCalls,
            'results' => $results,
            // ما الذي تغيّر: مقارنةٌ تقول «تراجَعَ» ولا تقول «لماذا» ملاحظةٌ لا قرار
            'prompt_versions' => self::promptVersions($results),
            'models' => [
                'gemini' => (string) config('services.gemini.model'),
                'glm' => (string) config('services.glm.model'),
            ],
        ]);
    }

    /** التشغيل المكتمل السابق — أساس المقارنة. */
    public static function previousRun(): ?AiEvaluationRun
    {
        return AiEvaluationRun::query()->orderByDesc('id')->skip(1)->first();
    }

    public static function latestRun(): ?AiEvaluationRun
    {
        return AiEvaluationRun::query()->orderByDesc('id')->first();
    }

    /**
     * الفرق عن التشغيل السابق لكل مهمّة.
     *
     * **المتوسّط يخفي التراجع**: قد يرتفع المجموع بينما تهبط مهمّةٌ بعينها، والهابطة هي
     * الخطر. لذلك تُقارَن كل مهمّة على حدة، و«مهمّة جديدة» تُميَّز عن «تراجعت» — الأولى
     * لا سابق لها فلا يصحّ عدّها تحسّناً ولا تراجعاً.
     *
     * @param  array<int,array<string,mixed>>  $results
     * @return array<int,array{task:string,rate:float,previous:float|null,delta:float|null,regressed:bool,isNew:bool}>
     */
    public static function diff(array $results, ?AiEvaluationRun $previous = null): array
    {
        $before = $previous?->byTask() ?? [];

        return array_map(function (array $r) use ($before) {
            $task = (string) $r['task'];
            $prev = isset($before[$task]) ? (float) $before[$task]['rate'] : null;

            return [
                'task' => $task,
                'rate' => (float) $r['rate'],
                'previous' => $prev,
                'delta' => $prev === null ? null : round($r['rate'] - $prev, 3),
                'regressed' => $prev !== null && $r['rate'] < $prev,
                'isNew' => $prev === null,
            ];
        }, $results);
    }

    /** هل تراجعت مهمّة واحدة على الأقلّ؟ — البوّابة التي تمنع اعتماد نموذج أو تعليمة. */
    public static function hasRegression(array $diff): bool
    {
        return array_filter($diff, fn (array $d) => $d['regressed']) !== [];
    }

    /**
     * إصدارات التعليمات المستعملة في التشغيل.
     *
     * @param  array<int,array<string,mixed>>  $results
     * @return array<string,string|null>
     */
    private static function promptVersions(array $results): array
    {
        $versions = [];
        foreach ($results as $r) {
            $versions[$r['task']] = AiPromptRegistry::version((string) $r['task']);
        }

        return $versions;
    }

    /**
     * يعلن أن تشغيلاً حيّاً انطلق. بدونه تعرض الشاشة الحصيلة السابقة بلا إشارة إلى
     * أن تشغيلاً جارٍ الآن، فيُقرأ القديم على أنه الجديد.
     */
    public static function markRunning(?string $by = null): void
    {
        Setting::put(self::SETTING_KEY, json_encode([
            'at' => now()->toDateTimeString(),
            'live' => true,
            'cost' => null,
            'by' => $by,
            'failure' => null,
            'running' => true,
            'results' => self::lastRun()['results'] ?? [],
        ], JSON_UNESCAPED_UNICODE));
    }

    /** @return array{at:string,live:bool,cost:float|null,by:string|null,failure:string|null,running:bool,results:array}|null */
    public static function lastRun(): ?array
    {
        $stored = json_decode((string) Setting::get(self::SETTING_KEY, ''), true);

        return is_array($stored) && isset($stored['results']) ? $stored : null;
    }

    /**
     * نداء المزوّد بمدخل الحالة المصطنَع، بصياغة الإنتاج نفسها — تقييمٌ بصياغة
     * أخرى يقيس شيئاً لا يعمل به النظام.
     *
     * @param  array<int,string>  $roster
     */
    private function callProvider(string $task, array $input, array $roster): mixed
    {
        $ai = $this->ai ??= app(LegalAiService::class);

        [$system, $prompt] = match ($task) {
            'ticket.triage' => [
                AiPromptRegistry::ticketTriageSystem(),
                'نوع التذكرة: '.($input['ticket_type'] ?? '—')
                    ."\nالقسم الذي اختاره العميل: ".($input['client_department'] ?: '—')
                    ."\nتفاصيل الطلب:\n".($input['details'] ?? ''),
            ],
            'consult.analyze' => [
                AiPromptRegistry::consultAnalyzeSystem($roster),
                'استشارة CN-EVAL — الموضوع: «'.($input['subject'] ?? '')
                    .'»، النوع: استشارة، القناة: مكتب، الأولوية: عادية. حلّل وأعد JSON.',
            ],
            'document.analyze' => [
                AiPromptRegistry::documentAnalyzeSystem(),
                'نوع التذكرة: '.($input['ticket_type'] ?? '—')
                    ."\nاسم الملف: ".($input['file_name'] ?? '—')
                    ."\n\nمحتوى المستند:\n".($input['content'] ?? '')
                    ."\n\nافحص المحتوى وأعد JSON.",
            ],
            'meeting.decisions' => [
                AiPromptRegistry::decisionsSystem(),
                (string) ($input['text'] ?? ''),
            ],
            default => [null, null],
        };

        if ($system === null) {
            return null;
        }

        $call = $ai->evaluationCall($system, $prompt);
        $this->liveCalls++;

        if ($call['cost'] === null) {
            $this->costComplete = false;
        } else {
            $this->cost += $call['cost'];
        }

        return $call['data'];
    }

    /**
     * المحقِّق الخادميّ نفسه الذي يعمل في الإنتاج — لا نسخة اختبار منه، وإلّا قِيس
     * شيءٌ لا يعمل به النظام.
     *
     * @param  array<int,string>  $roster
     * @param  array<int,string>  $refs  معرّفات المصادر الموجودة (لمهمّة الصياغة)
     */
    private static function validate(string $task, mixed $output, array $roster, array $refs = []): ?array
    {
        if (! is_array($output)) {
            return null;
        }

        return match ($task) {
            'ticket.triage' => AiOutputValidator::ticketTriage($output),
            'document.analyze' => AiOutputValidator::documentAnalysis($output),
            'consult.analyze' => AiOutputValidator::consultAnalysis($output, $roster),
            'execution.analyze' => AiOutputValidator::executionAnalysis($output, ['إجراء افتراضيّ']),
            'ticket.summary' => AiOutputValidator::ticketSummary($output),
            'meeting.decisions' => AiOutputValidator::decisions($output),
            'case.pleading' => LegalClaims::validate($output, $refs),
            default => null,
        };
    }

    /** أوّل حقل خالف المرجع، أو `null` إن طابق كلّه. */
    private static function firstMismatch(array $valid, array $expect): ?string
    {
        // حقول قيمتها نصّ أو منطق
        foreach (['department', 'priority', 'intent', 'lawyer', 'related', 'doc_type', 'verdict'] as $field) {
            if (array_key_exists($field, $expect) && ($valid[$field] ?? null) !== $expect[$field]) {
                return "{$field}: مرجعه «".self::render($expect[$field]).'» ونتيجته «'.self::render($valid[$field] ?? null).'»';
            }
        }

        // حقول يُقاس عددها لا محتواها — أهمّها `decisions_count`: به يُكشف اختلاق
        // قرارٍ لم يرد في المحضر، وهو ما لا تكشفه صحّة البنية
        foreach (['decisions' => 'decisions_count', 'claims' => 'claims_count', 'unsupported_claims' => 'unsupported_count'] as $field => $key) {
            if (array_key_exists($key, $expect) && count((array) ($valid[$field] ?? [])) !== $expect[$key]) {
                return "{$key}: مرجعه {$expect[$key]} ونتيجته ".count((array) ($valid[$field] ?? []));
            }
        }

        // وجودُ حقلٍ من عدمه (ملخّص المرفقات حين تُفحص مرفقات فعلاً)
        if (array_key_exists('has_attachments_summary', $expect)) {
            $has = trim((string) ($valid['attachments_summary'] ?? '')) !== '';
            if ($has !== $expect['has_attachments_summary']) {
                return 'attachments_summary: مرجعه '.($expect['has_attachments_summary'] ? 'موجود' : 'غائب').' ونتيجته '.($has ? 'موجود' : 'غائب');
            }
        }

        return null;
    }

    /** عرض قيمة في رسالة سقوط — المنطقيّة تُكتب كلمةً لا فراغاً. */
    private static function render(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'نعم' : 'لا',
            $value === null, $value === '' => '—',
            default => (string) $value,
        };
    }
}
