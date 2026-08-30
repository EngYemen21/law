<?php

namespace App\Console\Commands;

use App\Models\LegalSource;
use App\Services\Ai\AiEvaluator;
use App\Services\Ai\AiOpsMetrics;
use App\Services\Ai\AiReviewInbox;
use App\Services\Ai\AiReviewReason;
use Illuminate\Console\Command;

/**
 * جدول أعمال اجتماع الحوكمة الدوريّ — مخرَجٌ نصّيّ يُلصق في المحضر.
 *
 * الشاشة (`/admin/ai-ops`) تعرض هذه الأرقام حيّة، وهذا الأمر لِما لا تصلح له شاشة:
 * محضرٌ مؤرَّخ يُرفق بالاجتماع، وتشغيلٌ مجدول يصل بالبريد أو يُحفظ ملفّاً.
 *
 * والقرار المطلوب في كل اجتماع **محدَّد**: قبولُ أو إيقافُ أو تعديلُ وظيفةٍ بعينها —
 * لا تقييم انطباعيّ بأن «الذكاء جيّد». لذلك يُخرج التقرير أسباب الرفض مفصَّلة
 * ومرتَّبة بالأخطر أوّلاً، لا متوسّطاً واحداً يخفي تراجع فئة.
 */
class AiGovernanceReport extends Command
{
    protected $signature = 'ai:report {--days=30 : فترة التقرير بالأيام}';

    protected $description = 'يُخرج جدول أعمال اجتماع الحوكمة (مؤشّرات · أسباب الرفض · الكلفة · التقييم)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $metrics = AiOpsMetrics::snapshot($days);

        $this->line(str_repeat('═', 64));
        $this->line("تقرير حوكمة الذكاء الاصطناعي — آخر {$days} يوماً");
        $this->line('تاريخ الإصدار: '.now()->toDateTimeString());
        $this->line(str_repeat('═', 64));

        // ── 1. حجم التشغيل ──
        $this->newLine();
        $this->line('■ حجم التشغيل');
        if ((int) $metrics['total'] === 0) {
            // صفرُ نداءات ليس «أداءً ممتازاً»: لا قياس أصلاً، والفارق جوهريّ
            $this->warn('  لا نداءات في الفترة — لا شيء يُقاس، وليست نتيجةً جيّدة.');
        } else {
            $this->line("  نداءات: {$metrics['total']}");
            $this->line('  احتياطيّ: '.self::pct($metrics['fallback_rate']));
            $this->line('  فشل: '.self::pct($metrics['failure_rate']));
            $this->line('  احتاج مراجعة: '.self::pct($metrics['needs_review_rate']));
            $this->line('  احتاج تعديلاً بشرياً: '.self::pct(AiReviewInbox::humanEditRate($days)));
            $this->line('  زمن P50/P95: '.($metrics['latency_p50_ms'] ?? '—').' / '.($metrics['latency_p95_ms'] ?? '—').' م.ث');
        }

        // ── 2. أسباب الرفض ──
        $this->newLine();
        $this->line('■ أسباب الرفض — مادّة القرار');
        $reasons = AiReviewInbox::rejectionReasons($days);
        if ($reasons === []) {
            $this->line('  لا رفض مسجَّل في الفترة.');
        } else {
            $rows = [];
            foreach ($reasons as $code => $total) {
                $reason = AiReviewReason::tryFrom((string) $code);
                $rows[] = [$reason?->label() ?? (string) $code, (int) $total, $reason?->isHighRisk() ? 'خطورة عالية' : ''];
            }
            usort($rows, fn ($a, $b) => [$b[2] !== '', $b[1]] <=> [$a[2] !== '', $a[1]]);
            $this->table(['السبب', 'العدد', 'التصنيف'], $rows);
            $this->warn('  تراجعُ فئة عالية الخطورة يمنع اعتماد نموذج أو تعليمة جديدة ولو تحسّن المتوسّط العامّ.');
        }

        // ── 3. أسباب التعذّر ──
        $failures = AiOpsMetrics::failureCodes($days);
        if ($failures !== []) {
            $this->newLine();
            $this->line('■ أسباب التعذّر');
            foreach ($failures as $code => $total) {
                $this->line("  {$code}: {$total}");
            }
        }

        // ── 4. الكلفة ──
        $this->newLine();
        $this->line('■ الكلفة');
        $this->line('  التوكنات: '.number_format((int) $metrics['total_tokens']));
        if ($metrics['estimated_cost'] === null) {
            $this->warn('  الكلفة غير معلومة — أسعار النماذج غير مضبوطة في /admin/ai-ops.');
        } else {
            $this->line("  الكلفة التقديريّة: {$metrics['estimated_cost']}");
            if ($metrics['cost_coverage'] !== null && $metrics['cost_coverage'] < 1) {
                $this->warn('  المجموع جزئيّ — تغطية '.self::pct($metrics['cost_coverage']).' من النداءات فقط.');
            }
        }

        // ── 5. قاعدة المصادر ──
        $this->newLine();
        $this->line('■ قاعدة المصادر القانونيّة');
        $approved = LegalSource::where('status', LegalSource::STATUS_APPROVED)->count();
        $draft = LegalSource::where('status', LegalSource::STATUS_DRAFT)->count();
        $this->line("  معتمدة: {$approved} · بانتظار الاعتماد: {$draft}");
        if ($approved === 0) {
            $this->warn('  لا مصدر معتمد — الاستشهاد معطَّل فعلياً والمسودات تُنتَج بلا سند.');
        }

        // ── 6. آخر تقييم ──
        $this->newLine();
        $this->line('■ مجموعة التقييم');
        $last = AiEvaluator::lastRun();
        if ($last === null) {
            $this->warn('  لم تُشغَّل بعد — لا خطّ أساس تُقارَن به أي ترقية نموذج.');
        } else {
            $this->line("  آخر تشغيل: {$last['at']} — ".($last['live'] ? 'حيّ' : 'جافّ'));
            foreach ($last['results'] as $r) {
                $verdict = $r['meets'] ? 'عبرت' : 'سقطت';
                $this->line("  {$r['task']}: {$r['passed']}/{$r['total']} — {$verdict}");
            }

            // الاتّجاه لا الحالة: «90% اليوم» لا تُقرأ حتى يُعرف أهي صعودٌ أم هبوط
            $latest = AiEvaluator::latestRun();
            $previous = AiEvaluator::previousRun();

            if ($latest !== null && $previous !== null) {
                $diff = AiEvaluator::diff($latest->results, $previous);
                $moved = array_values(array_filter($diff, fn ($d) => ! $d['isNew'] && $d['delta'] != 0.0));

                $this->line('  الفرق عن '.$previous->created_at->toDateTimeString().':');
                if ($moved === []) {
                    $this->line('    بلا تغيّر في أي مهمّة.');
                }
                foreach ($moved as $d) {
                    $this->line("    {$d['task']}: ".round($d['previous'] * 100).'% ← '.round($d['rate'] * 100).'%');
                }

                if (AiEvaluator::hasRegression($diff)) {
                    $this->warn('  تراجعت مهمّة — لا يُعتمد النموذج أو التعليمة ولو عبرت كل البوّابات.');
                }
            } elseif ($latest !== null) {
                $this->line('  تشغيلٌ واحد مسجَّل — لا اتّجاه يُقرأ بعد.');
            }
        }

        // ── 7. التنبيهات ──
        $alerts = AiOpsMetrics::alerts($days);
        if ($alerts !== []) {
            $this->newLine();
            $this->line('■ تنبيهات');
            foreach ($alerts as $alert) {
                $this->warn('  '.$alert['message']);
            }
        }

        $this->newLine();
        $this->line(str_repeat('─', 64));
        $this->line('القرار المطلوب: قبولُ أو إيقافُ أو تعديلُ وظيفةٍ بعينها — لا تقييم عامّ.');

        return self::SUCCESS;
    }

    /** «غير مقيسة» لا صفر: الصفر يقول إن القياس جرى ونتيجته صفر. */
    private static function pct(?float $value): string
    {
        return $value === null ? 'غير مقيسة' : round($value * 100).'%';
    }
}
