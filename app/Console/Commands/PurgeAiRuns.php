<?php

namespace App\Console\Commands;

use App\Models\AiRun;
use App\Services\Ai\AiDataClass;
use Illuminate\Console\Command;

/**
 * تنفيذ سياسة الاحتفاظ على سجلّ الذكاء — مطلب المرحلة P5.
 *
 * المسح على مرحلتين لا مرحلة: القيد **يُجرَّد** من حقوله السرّية أوّلاً ويبقى
 * هيكله للقياس التاريخيّ، ثم يُحذف كليّاً بعد أطول مدّة. فحذفُ كل شيء دفعةً
 * يُفقد المكتبَ قدرتَه على قول «كم مخرجاً أُنتج العام الماضي وكم رُفض» — وهو
 * سؤال حوكمة مشروع.
 *
 * **جافّ افتراضياً**: لا يحذف شيئاً حتى يُمرَّر `--force`. حذف بيانات لا يقع
 * بالخطأ من أمرٍ مجدول.
 */
class PurgeAiRuns extends Command
{
    protected $signature = 'ai:purge {--force : نفِّذ الحذف فعلياً بدل العرض}';

    protected $description = 'تطبيق سياسة الاحتفاظ على سجلّ تشغيل الذكاء الاصطناعي (تجريد ثم حذف)';

    public function handle(): int
    {
        $confidentialDays = AiDataClass::Confidential->retentionDays();
        $internalDays = AiDataClass::Internal->retentionDays();
        $dryRun = ! $this->option('force');

        if ($confidentialDays === null && $internalDays === null) {
            $this->info('لا مدّة احتفاظ مضبوطة — لا شيء يُمسَح.');

            return self::SUCCESS;
        }

        // (1) تجريد الحقول السرّية مع إبقاء الهيكل التشغيليّ
        $toStrip = $confidentialDays === null ? null : AiRun::query()
            ->where('created_at', '<', now()->subDays($confidentialDays))
            ->whereNotNull('entity_ref');

        $stripCount = $toStrip?->count() ?? 0;

        // (2) الحذف الكامل بعد أطول مدّة
        $toDelete = $internalDays === null ? null : AiRun::query()
            ->where('created_at', '<', now()->subDays($internalDays));

        $deleteCount = $toDelete?->count() ?? 0;

        $this->line("قيود للتجريد (أقدم من {$confidentialDays} يوماً): {$stripCount}");
        $this->line("قيود للحذف (أقدم من {$internalDays} يوماً): {$deleteCount}");

        if ($dryRun) {
            $this->warn('عرض فقط — أضِف --force للتنفيذ.');

            return self::SUCCESS;
        }

        if ($toStrip !== null && $stripCount > 0) {
            // تُصفَّر المعرّفات والملاحظات؛ تبقى المهمّة والمصدر والحالة والزمن.
            //
            // و`outbound_audit` **لا يُجرَّد هنا** عمداً: أعدادٌ وحجمٌ لا محتوى، ومصنَّف
            // «داخليّ» فمدّته أطول. وهو دليل تقليل البيانات — فيجب أن يعمّر أطول ممّا
            // يُثبته، وإلّا سقط الإثبات قبل انقضاء مدّة المساءلة عنه.
            $toStrip->update([
                'entity_type' => null,
                'entity_id' => null,
                'entity_ref' => null,
                'review_note' => null,
                'confidence_signals' => null,
            ]);
            $this->info("جُرِّد {$stripCount} قيداً من حقوله السرّية.");
        }

        if ($toDelete !== null && $deleteCount > 0) {
            $toDelete->delete();
            $this->info("حُذف {$deleteCount} قيداً.");
        }

        return self::SUCCESS;
    }
}
