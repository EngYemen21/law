<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * **فتح الدخول ربع ساعة قبل الموعد** (قرار المالك 2026-10-01): الافتراض صار 15 بدل 5. ومن حفظ القيمة
 * القديمة 5 كما هي يُنقل إليها — وأيّ قيمةٍ أخرى ضبطتها الإدارة تبقى. ولا تُكسر علاقات الإعدادات:
 * لا يتجاوز «بدءَ الطاقم» ولا يبلغ «التذكير القريب» (`gte` · `gt` في `SettingsRegistry`).
 */
return new class extends Migration
{
    private const KEY = 'session_join_opens_minutes';

    public function up(): void
    {
        if (DB::table('settings')->where('key', self::KEY)->value('value') !== '5') {
            return;
        }

        $saved = fn (string $key) => DB::table('settings')->where('key', $key)->value('value');
        $limit = 15;
        if (is_numeric($v = $saved('consult_staff_start_minutes'))) {
            $limit = min($limit, (int) $v);
        }
        foreach (['meeting_reminder_near_minutes', 'consult_reminder_near_minutes'] as $near) {
            if (is_numeric($v = $saved($near))) {
                $limit = min($limit, (int) $v - 1);
            }
        }

        if ($limit > 5) {
            DB::table('settings')->where('key', self::KEY)->update(['value' => (string) $limit, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // القيمة السابقة لا تُعرف بعد التشغيل (قد تكون الإدارة غيّرتها) — يُترك ما في القاعدة.
    }
};
