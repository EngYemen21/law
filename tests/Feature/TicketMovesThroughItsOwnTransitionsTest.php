<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **التذكرة تتحرّك بانتقالاتها هي — لا يكتب انتقالُ استشارةٍ حالتَها.**
 *
 * كانت أربعة انتقالات استشارة (السداد · نشر الموعد · إعادة الجدولة · إلغاء الطلب) تكتب
 * `$ticket->update(['status' => …])` مباشرةً، فتتغيّر حالة التذكرة **بلا سطرٍ في سجلّ رحلتها**:
 * رُصد حيّاً على SB-2026-3286 (2026-09-25). والمحرّك يسمح بها لأنّها تقع داخل معاملته، فلا
 * يلتقطها `StateWriteGuard`.
 *
 * صارت أربعتُها تنادي انتقالاتٍ للتذكرة (`TicketAwaitsSchedule` · `TicketScheduled` ·
 * `TicketBookingWithdrawn`) داخل المعاملة نفسها. وهذا الحارس يمنع عودة الكتابة الجانبيّة.
 */
class TicketMovesThroughItsOwnTransitionsTest extends TestCase
{
    /** كتابةُ `status` على تذكرةٍ بـ`update` أو `forceFill` — داخل مصفوفةٍ واحدة. */
    private const SIDE_WRITE = "/ticket\\??->(?:update|forceFill|fill)\\(\\s*\\[[^\\]]*['\"]status['\"]\\s*=>/s";

    public function test_no_consult_transition_writes_the_ticket_status_itself(): void
    {
        $files = glob(app_path('Domain/Journey/Transitions/Consult/*.php')) ?: [];
        $this->assertGreaterThan(10, count($files), 'لم تُقرأ انتقالات الاستشارة — تحقّق من المسار.');

        $offenders = [];
        foreach ($files as $file) {
            // التعليقات لا تُحسب: شرحُ سبب الإصلاح يذكر الكتابة القديمة
            $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));

            if (preg_match(self::SIDE_WRITE, $code)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'انتقال استشارةٍ يكتب حالة التذكرة بنفسه — نادِ انتقالاً للتذكرة بـWorkflow::run: '.implode('، ', $offenders));
    }

    /** ولا يمرّ فراغاً: النمط يلتقط الكتابة القديمة حرفيّاً. */
    public function test_the_pattern_catches_the_old_side_write(): void
    {
        $old = "\$ticket->update([\n    'status' => TicketStatus::Scheduled->value,\n    'tone' => 'x',\n]);";

        $this->assertMatchesRegularExpression(self::SIDE_WRITE, $old);
        $this->assertDoesNotMatchRegularExpression(self::SIDE_WRITE, "\$ticket->update(['last_message' => 'x']);");
    }
}
