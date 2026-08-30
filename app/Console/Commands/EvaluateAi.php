<?php

namespace App\Console\Commands;

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
            ['المهمّة', 'ناجحة', 'النسبة', 'البوّابة', 'الحصيلة'],
            array_map(fn (array $r) => [
                $r['task'],
                "{$r['passed']}/{$r['total']}",
                round($r['rate'] * 100).'%',
                round($r['gate'] * 100).'%',
                $r['meets'] ? 'عبرت' : 'سقطت',
            ], $results),
        );

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

        AiEvaluator::remember($results, $live, $evaluator->cost(), 'أمر مجدوَل');

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
