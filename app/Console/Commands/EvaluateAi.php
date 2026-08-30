<?php

namespace App\Console\Commands;

use App\Models\AiEvaluationRun;
use App\Services\Ai\AiEvaluator;
use Illuminate\Console\Command;

/**
 * تشغيل مجموعة التقييم — الطبقة الثانية.
 *
 * الشاشة (`/admin/ai-ops`) هي واجهة المكتب لهذا التقييم؛ وهذا الأمر لِما لا تصلح له
 * شاشة: الجدولة الدوريّة، والتشغيل قبل كل نشر في خطّ التكامل.
 *
 * الخطة تفرض: «لا تغيير في تعليمة أو نموذج قبل تشغيل مجموعة التقييم ومقارنة النتيجة
 * بالسابقة» — ولا يجوز أن يمنع غيابُ متصفّحٍ تنفيذَ هذا الشرط.
 */
class EvaluateAi extends Command
{
    protected $signature = 'ai:evaluate
        {--live : نداء حقيقيّ للمزوّد (يستهلك حصّة). الافتراضيّ مقارنةٌ بالمخرجات المثبَّتة}
        {--task=* : قصر التقييم على مهامّ بعينها (مثل ticket.triage)}
        {--force : السماح بالتشغيل في بيئة الإنتاج}';

    protected $description = 'يشغّل حالات التقييم المصطنعة ويقارن المخرجات ببوّابات الخطة';

    public function handle(): int
    {
        $live = (bool) $this->option('live');

        // النداء الحيّ في الإنتاج يستهلك حصّة الخدمة الفعليّة — قرارٌ صريح لا افتراضيّ
        if ($live && app()->environment('production') && ! $this->option('force')) {
            $this->error('نداء حيّ في بيئة الإنتاج يحتاج --force صراحةً.');

            return self::FAILURE;
        }

        $evaluator = new AiEvaluator;
        $results = $evaluator->run((array) $this->option('task'), $live);

        if ($results === []) {
            $this->error('لا حالات مطابقة. تأكّد من tests/Fixtures/ai/ ومن قيمة --task.');

            return self::FAILURE;
        }

        $this->line($live ? 'تقييم حيّ على المزوّد المهيَّأ — وما تعذّر تشغيله حيّاً يُعلَن أدناه بسببه.' : 'تقييم جافّ على المخرجات المثبَّتة (بلا نداء شبكيّ).');
        $this->newLine();

        $this->table(
            ['المهمّة', 'ناجحة', 'مؤجَّلة', 'النسبة', 'البوّابة', 'الحصيلة'],
            array_map(fn (array $r) => [
                $r['task'],
                "{$r['passed']}/{$r['total']}",
                $r['skipped'] ?: '—',
                round($r['rate'] * 100).'%',
                round($r['gate'] * 100).'%',
                $r['meets'] ? 'عبرت' : 'سقطت',
            ], $results),
        );

        // لا اقتطاع صامت: حالةٌ لم تُقَس تُعلَن، وإلّا قُرئت «100%» على أنها تغطية كاملة
        $deferred = array_sum(array_column($results, 'skipped'));
        if ($deferred > 0) {
            $this->warn("{$deferred} حالة مؤجَّلة: توقُّعها معلَّق بحكم النموذج (اختلاق/حقن)، "
                .'ولا تُقاس على مخرجٍ مثبَّت — تحتاج --live.');
        }

        foreach ($results as $r) {
            if ($r['liveSkipped']) {
                $this->warn("{$r['task']}: لم يُشغَّل حيّاً — {$r['liveSkipped']}");
            }

            foreach ($r['failures'] as $failure) {
                $this->line("  <fg=red>✗</> {$r['task']} — {$failure}");
            }
        }

        $failed = array_values(array_filter($results, fn (array $r) => ! $r['meets']));

        if ($failed !== []) {
            $this->newLine();
            // شرط صريح في الخطة: سقوط فئةٍ يمنع الاعتماد ولو تحسّن المتوسّط العامّ
            $this->error('سقطت '.count($failed).' مهمّة دون بوّابتها — لا يُعتمد النموذج/التعليمة ولو تحسّن المتوسّط العامّ.');
        }

        if ($live) {
            $cost = $evaluator->cost();
            $this->line("نداءات حيّة: {$evaluator->liveCalls()} — الكلفة: "
                .($cost === null ? 'غير معلومة (سعر النموذج غير مضبوط في اللوحة)' : '$'.$cost));
        }

        // خطّ الأساس يُلتقَط **قبل** التسجيل: بعده يصير هذا التشغيل هو الأحدث
        $baseline = AiEvaluator::latestRun();

        AiEvaluator::remember($results, $live, $evaluator->cost(), 'أمر مجدوَل', liveCalls: $evaluator->liveCalls());

        $regressed = $this->reportDiff($results, $baseline);

        return $failed === [] && ! $regressed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * الفرق عن التشغيل السابق. يُعيد `true` إن تراجعت مهمّة.
     *
     * التراجع يُسقط الأمر **ولو عبرت كل البوّابات**: مهمّةٌ هبطت من 100% إلى 96% تعبر
     * بوّابة 90% وهي إشارة تدهور. وخطّ التكامل يقرأ رمز الخروج لا النصّ.
     */
    private function reportDiff(array $results, ?AiEvaluationRun $baseline): bool
    {
        if ($baseline === null) {
            $this->newLine();
            $this->line('أوّل تشغيل مسجَّل — صار خطَّ الأساس، ولا سابق يُقارَن به.');

            return false;
        }

        $diff = AiEvaluator::diff($results, $baseline);

        $this->newLine();
        $this->line("الفرق عن التشغيل السابق ({$baseline->created_at->toDateTimeString()}):");

        foreach ($diff as $d) {
            if ($d['isNew']) {
                $this->line("  {$d['task']}: مهمّة جديدة — لا سابق لها");

                continue;
            }
            if ($d['delta'] == 0.0) {
                continue;
            }

            $arrow = $d['regressed'] ? '<fg=red>▼</>' : '<fg=green>▲</>';
            $this->line("  {$arrow} {$d['task']}: ".round($d['previous'] * 100).'% ← '.round($d['rate'] * 100).'%');
        }

        if (! AiEvaluator::hasRegression($diff)) {
            return false;
        }

        // المتوسّط يخفي التراجع: قد يرتفع المجموع بينما تهبط مهمّة، والهابطة هي الخطر
        $this->error('تراجعت مهمّة عن تشغيلها السابق — لا يُعتمد النموذج/التعليمة ولو عبرت كل البوّابات.');

        return true;
    }
}
