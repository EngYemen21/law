<?php

namespace Tests\Feature;

use App\Domain\Journey\StateWriteGuard;
use Tests\TestCase;

/**
 * **قائمة الكتّاب القدامى تتقلّص ولا تتعفّن.**
 *
 * كلّ بندٍ في `StateWriteGuard::LEGACY_WRITERS` يُعفي **ملفّاً كاملاً** من الحارس. فبندٌ بائت
 * (ملفٌّ حُذف أو صار يمرّ بالمحرّك) يترك باباً مفتوحاً لكتابةٍ جديدة لا يراها أحد — وقد وُجد
 * منها ستّة في جرد 2026-09-18. والجرد نفسه يُعاد بـ`JOURNEY_RECORD_LEGACY=true` في وضع `record`.
 */
class LegacyWritersListTest extends TestCase
{
    /**
     * **سقفٌ لا يرتفع:** فرغت القائمة في 2026-09-19 بنقل كلّ الكتّاب إلى المحرّك. إضافةُ بندٍ تُسقط
     * هذا الاختبار عمداً — فمن احتاج إعفاءً مؤقّتاً يرفع السقف هنا بتعليقٍ يشرح لماذا ومتى يزول.
     */
    public function test_the_list_stays_empty(): void
    {
        $this->assertSame([], StateWriteGuard::LEGACY_WRITERS, 'كاتبٌ جديد خارج المحرّك — انقله إلى انتقالٍ أو `Workflow::open` بدل إعفائه');
    }

    public function test_every_entry_is_an_existing_file_listed_once(): void
    {
        $list = StateWriteGuard::LEGACY_WRITERS;

        $this->assertSame(array_values(array_unique($list)), $list, 'بندٌ مكرّر');

        foreach ($list as $entry) {
            $this->assertFileExists(base_path($entry), "بندٌ لملفٍّ غير موجود — يُحذف من القائمة: {$entry}");
        }
    }

    /**
     * **البند يلزمه كتابةٌ مباشرة يُعفى منها.** ملفٌّ لا يحوي أيّ إسنادٍ لعمودٍ مراقَب لا سبب
     * لإعفائه. فحصٌ نصّيّ متسامح: يكفي ذكرُ عمودٍ مراقَب في مصفوفة كتابة أو إسناد.
     */
    public function test_every_entry_still_writes_a_watched_column(): void
    {
        $this->assertNotEmpty(StateWriteGuard::WATCHED); // والقائمة الفارغة تمرّ بلا حلقة
        $columns = collect(StateWriteGuard::WATCHED)->flatten()->unique()->implode('|');

        foreach (StateWriteGuard::LEGACY_WRITERS as $entry) {
            $source = (string) file_get_contents(base_path($entry));
            $this->assertMatchesRegularExpression(
                // مصفوفة كتابة `'status' =>` · إسناد `->status =` · بناء مصفوفة `$updates['status'] =`
                "/(['\"](?:{$columns})['\"]\s*=>|->(?:{$columns})\s*=(?!=)|\[['\"](?:{$columns})['\"]\]\s*=(?!=))/",
                $source,
                "بندٌ بائت — الملفّ لا يكتب عموداً مراقَباً، فيُحذف من القائمة: {$entry}"
            );
        }
    }
}
