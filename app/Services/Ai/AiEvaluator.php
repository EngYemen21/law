<?php

namespace App\Services\Ai;

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
    /** بوّابات العبور من الخطة — تُعاير مع الفريق القانونيّ لاحقاً. */
    public const GATES = [
        'ticket.triage' => 0.90,
        'consult.analyze' => 0.90,
        'execution.analyze' => 0.90,
    ];

    /**
     * ما يمكن تشغيله حيّاً. حالات `execution.analyze` تصف **إشارات** (عدد المستندات،
     * وجود سند) لا نصّاً يُرسَل لنموذج، فلا مدخل حيّاً لها. تُعلَن جافّة صراحةً بدل
     * أن يُوهم التقرير بأنها اختُبرت على النموذج.
     */
    public const LIVE_CAPABLE = ['ticket.triage', 'consult.analyze'];

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
        $failures = [];
        $passed = 0;

        foreach ((array) $set['cases'] as $case) {
            $output = $live
                ? $this->callProvider($task, (array) $case['input'], $roster)
                : ($case['model_output'] ?? null);

            $valid = self::validate($task, $output, $roster);
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

        $total = count((array) $set['cases']);
        $rate = $total > 0 ? round($passed / $total, 3) : 0.0;
        $gate = self::GATES[$task] ?? 0.90;

        return [
            'task' => $task,
            'total' => $total,
            'passed' => $passed,
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
    public static function remember(array $results, bool $live, ?float $cost, ?string $by = null, ?string $failure = null): void
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

    /** @param array<int,string> $roster */
    private static function validate(string $task, mixed $output, array $roster): ?array
    {
        if (! is_array($output)) {
            return null;
        }

        return match ($task) {
            'ticket.triage' => AiOutputValidator::ticketTriage($output),
            'consult.analyze' => AiOutputValidator::consultAnalysis($output, $roster),
            'execution.analyze' => AiOutputValidator::executionAnalysis($output, ['إجراء افتراضيّ']),
            default => null,
        };
    }

    /** أوّل حقل خالف المرجع، أو `null` إن طابق كلّه. */
    private static function firstMismatch(array $valid, array $expect): ?string
    {
        foreach (['department', 'priority', 'intent', 'lawyer'] as $field) {
            if (array_key_exists($field, $expect) && ($valid[$field] ?? null) !== $expect[$field]) {
                return "{$field}: مرجعه «{$expect[$field]}» ونتيجته «".($valid[$field] ?: '—').'»';
            }
        }

        return null;
    }
}
