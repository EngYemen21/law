<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * **مهل الإعدادات بالدقائق لا بالساعات** (قرار المالك 2026-09-26).
 *
 * كانت أربع مهلٍ في `SettingsRegistry` بالساعات، فلا تستطيع الإدارة ضبط ٥ أو ١٠ دقائق. صارت
 * مفاتيحها بالدقائق — والمفتاح نفسه يقول وحدته، فالمفتاح القديم يُعاد تسميته لا يُعاد تفسيره: لو
 * بقي `session_stale_hours` يحمل دقائق لقرأ قارئٌ قديمٌ ٣٦٠ ساعة.
 *
 * **ما حفظته الإدارة لا يضيع:** كلّ صفٍّ محفوظٍ بمفتاحٍ قديم يُنقل إلى مفتاحه الجديد بقيمته × ٦٠.
 * وما لم يُحفظ قطّ لا صفّ له — يقرأ افتراضه الجديد من السجلّ (المساوي للقديم × ٦٠). وإن وُجد
 * المفتاح الجديد (تشغيلٌ ثانٍ، أو ضبطٌ بعد النشر) فهو الأحدث ويُحذف القديم وحده.
 *
 * و`down()` تعكسها: القسمة على ٦٠ مقرّبةً، مقيّدةً بأدنى ما كان يقبله المفتاح القديم.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: int}> القديم ⇒ [الجديد، أدنى قيمةٍ قديمة] */
    private const RENAMES = [
        'consult_reschedule_notice_hours' => ['consult_reschedule_notice_minutes', 0],
        'consult_autoclose_hours' => ['consult_autoclose_minutes', 1],
        'meeting_autoclose_hours' => ['meeting_autoclose_minutes', 1],
        'session_stale_hours' => ['session_stale_minutes', 2],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::RENAMES as $old => [$new]) {
                $this->move($old, $new, fn (int $hours) => $hours * 60);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (self::RENAMES as $old => [$new, $min]) {
                $this->move($new, $old, fn (int $minutes) => max($min, (int) round($minutes / 60)));
            }
        });
    }

    /** @param  callable(int): int  $convert */
    private function move(string $from, string $to, callable $convert): void
    {
        $row = DB::table('settings')->where('key', $from)->first();
        if ($row === null) {
            return;
        }

        if (! DB::table('settings')->where('key', $to)->exists()) {
            DB::table('settings')->insert([
                'key' => $to,
                'value' => (string) $convert((int) $row->value),
                'created_at' => $row->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('settings')->where('key', $from)->delete();
    }
};
