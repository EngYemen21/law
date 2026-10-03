<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **تنظيف ما خلّفه ملخّص Zoom الفارغ** (ثبت على الإنتاج 2026-10-03: M-2026-3390 · M-2026-5888).
 *
 * قبل `ZoomService::summaryHasContent` كان الملخّص الفارغ يُحفظ عنواناً وحده «ملخص الاجتماع — {ref}»
 * ويُختم `zoom_summary_at` فيتوقّف الجلب، ويُرسَل العنوان لاستخراج القرارات فيختلق النموذج قراراتٍ تُحفظ
 * اقتراحاتٍ. هنا يُعاد ما كان كذلك إلى «لم يصل»: الصفّ الذي عموده `zoom_summary` هو العنوان بحرفه لا غير،
 * ولم تُنشأ منه مهامّ (`tasks_created`). والقرارات تُمسح حين تطابق الاقتراحات وحدها — أي حين كانت من
 * الاستخراج نفسه لا ممّا دوّنه أحد. ولا تُمسّ صفوفٌ أخرى.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['meetings' => 'ملخص الاجتماع', 'consults' => 'ملخص الاستشارة'] as $table => $heading) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'zoom_summary')) {
                continue;
            }

            $rows = DB::table($table)
                ->whereNotNull('zoom_summary')
                ->where('tasks_created', false)
                ->get(['id', 'ref', 'zoom_summary', 'decisions', 'suggested_tasks']);

            foreach ($rows as $row) {
                if (trim((string) $row->zoom_summary) !== "{$heading} — {$row->ref}") {
                    continue;
                }

                $clear = ['zoom_summary' => null, 'zoom_summary_at' => null, 'suggested_tasks' => null];
                if ($row->decisions === null || json_decode((string) $row->decisions, true) == json_decode((string) $row->suggested_tasks, true)) {
                    $clear['decisions'] = null;
                }
                if ($table === 'meetings') {
                    $summary = DB::table('meetings')->where('id', $row->id)->value('summary');
                    $clear['has_summary'] = trim((string) $summary) !== '';
                }

                DB::table($table)->where('id', $row->id)->update($clear);
            }
        }
    }

    public function down(): void
    {
        // تنظيفٌ لمخرجٍ مختلَق — لا يُعاد
    }
};
