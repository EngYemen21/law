<?php

namespace App\Console\Commands;

use App\Models\LegalCase;
use App\Support\CasePleading;
use Illuminate\Console\Command;

/**
 * **مسودّاتٌ حجبتها الهجرة بأثرٍ رجعيّ عن قضايا اعتُمدت لوائحها.**
 *
 * هجرة `add_withheld_at_to_case_messages` حجبت كلَّ مسودّات اللوائح القائمة — ومنها مسودّاتُ
 * قضايا اعتمد محاموها لوائحَها سابقاً بالزرّ القديم (الذي كان يرفع الدعوى ولا يُطلق النصّ).
 * فبقيت لوائحُ معتمدةٌ مخفيّةً عن أصحابها بلا إجراءٍ ينتظرها.
 *
 * **بلا `--apply` لا يُغيَّر شيء** — يعرض ما سيُطلَق فقط. ويُشغَّل على الإنتاج بقرار المالك.
 * ولا يُطلَق نصٌّ احتياطيّ ولا مرفوض: الحارس نفسه في `CasePleading::blockReason`.
 */
class ReleaseApprovedPleadings extends Command
{
    protected $signature = 'cases:release-approved-pleadings {--apply : أطلق فعلاً — بدونه عرضٌ فقط}';

    protected $description = 'إطلاق مسودّات لوائح القضايا المعتمدة التي بقيت محجوبة (عرضٌ فقط بلا --apply)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $released = 0;
        $skipped = 0;

        LegalCase::where('pleading_status', 'approved')->orderBy('id')->each(function (LegalCase $case) use ($apply, &$released, &$skipped) {
            $draft = CasePleading::latestDraft($case);
            if ($draft === null || $draft->withheld_at === null) {
                return;
            }

            $blocked = CasePleading::blockReason($case);
            if ($blocked !== null) {
                $skipped++;
                $this->line("⏭  {$case->number}: {$blocked}");

                return;
            }

            if ($apply) {
                CasePleading::releaseForApprovedCase($case);
            }
            $released++;
            $this->line(($apply ? '✅ أُطلقت' : '•  ستُطلَق').": {$case->number}");
        });

        $this->info(($apply ? 'أُطلقت' : 'ستُطلَق')." {$released} مسودّة، وتُركت {$skipped} محجوبةً لسببٍ معلَن.");
        if (! $apply && $released > 0) {
            $this->comment('للإطلاق فعلاً: php artisan cases:release-approved-pleadings --apply');
        }

        return self::SUCCESS;
    }
}
