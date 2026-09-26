<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\ArabicCount;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * **المهل بالدقائق، ونصُّها بوحدتها الطبيعيّة — قاعدةٌ واحدة في الخادم والواجهة** (قرار المالك 2026-09-26).
 *
 * صارت مهل الإعدادات بالدقائق لتُضبط من ٥ أو ١٠ دقائق، لكنّ ما يقرؤه العميل والطاقم لا يقول
 * «1440 دقيقة». فالصياغة في `ArabicCount::duration` ونظيرها `humanDuration` في الواجهة — والجدول
 * هنا واحدٌ يُشغَّل على الاثنين، فلا تفترق القاعدتان.
 */
class HumanDurationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> الدقائق ⇒ النصّ — أمثلة المالك أوّلاً ثمّ الحوافّ */
    private const TABLE = [
        10 => '10 دقائق',
        60 => 'ساعة واحدة',
        90 => 'ساعة ونصف',
        120 => 'ساعتين',
        150 => 'ساعتين ونصف',
        180 => '3 ساعات',
        1440 => 'يوم واحد',
        2880 => 'يومين',
        4320 => '3 أيام',
        75 => 'ساعة و15 دقيقة',
        // الحوافّ
        0 => '0 دقيقة',
        1 => 'دقيقة واحدة',
        2 => 'دقيقتين',
        5 => '5 دقائق',
        15 => '15 دقيقة',
        121 => 'ساعتين ودقيقة',
        210 => '3 ساعات ونصف',
        360 => '6 ساعات',
        720 => '12 ساعة',
        1500 => 'يوم وساعة',
        2160 => 'يوم ونصف',
        14325 => '9 أيام و22 ساعة',
    ];

    public function test_the_server_formatter_reads_like_a_person(): void
    {
        foreach (self::TABLE as $minutes => $text) {
            $this->assertSame($text, ArabicCount::duration($minutes), "{$minutes} دقيقة");
        }
    }

    /** الواجهة تصوغ بالقاعدة نفسها — تُحمَّل `human-duration.ts` في node وتُسأل عن الجدول نفسه. */
    public function test_the_frontend_formatter_matches_the_server_one(): void
    {
        if (! Process::run(['node', '--version'])->successful()) {
            $this->markTestSkipped('node غير متاح في هذه البيئة.');
        }

        $module = 'file:///'.ltrim(str_replace(chr(92), '/', resource_path('js/lib/human-duration.ts')), '/');
        $script = 'import { humanDuration } from '.json_encode($module).';'
            .' const keys = JSON.parse(process.argv[1]);'
            .' process.stdout.write(JSON.stringify(keys.map((m) => humanDuration(m))));';

        $result = Process::run(['node', '--input-type=module', '-e', $script, json_encode(array_keys(self::TABLE))]);
        if (! $result->successful()) {
            // node قديم بلا تجريد أنواع TypeScript — لا حكم هنا، والجدول يحرس الخادم
            $this->markTestSkipped('تعذّر تحميل ملفّ TypeScript في node: '.$result->errorOutput());
        }

        $this->assertSame(array_values(self::TABLE), json_decode($result->output(), true));
    }

    /**
     * **الحارس:** لا مفتاح مدّةٍ بالساعات في سجلّ الإعدادات (القائمة المسموحة فارغة). ساعات
     * الحجز (`consult_day_start/end`) ساعاتُ ساعةٍ حائطيّة لا مُدد، ولا تنتهي بـ`_hours`.
     */
    public function test_no_duration_setting_is_kept_in_hours(): void
    {
        $allowed = [];
        $hours = array_values(array_filter(
            array_keys(SettingsRegistry::all()),
            fn (string $key) => str_ends_with($key, '_hours') && ! in_array($key, $allowed, true),
        ));

        $this->assertSame([], $hours, 'مفاتيح مدّةٍ بالساعات: '.implode('، ', $hours));

        foreach (['consult_reschedule_notice_minutes', 'consult_autoclose_minutes', 'meeting_autoclose_minutes', 'session_stale_minutes'] as $key) {
            $this->assertStringContainsString('(دقائق)', SettingsRegistry::field($key)['label'], $key);
        }
        // الافتراضات القديمة × ٦٠ — السلوك قبل التحويل هو السلوك بعده
        $this->assertSame(
            [1440, 720, 720, 360],
            array_map(fn ($k) => SettingsRegistry::int($k), ['consult_reschedule_notice_minutes', 'consult_autoclose_minutes', 'meeting_autoclose_minutes', 'session_stale_minutes']),
        );
    }

    /** ما حفظته الإدارة بالساعات لا يضيع: المهاجرة تنقله إلى مفتاحه بالدقائق، و`down()` تعيده. */
    public function test_the_migration_carries_saved_hours_into_minutes_and_back(): void
    {
        $migration = require database_path('migrations/2026_09_26_130000_settings_hours_to_minutes.php');

        DB::table('settings')->insert([
            ['key' => 'session_stale_hours', 'value' => '8', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'consult_reschedule_notice_hours', 'value' => '0', 'created_at' => now(), 'updated_at' => now()],
            // ضُبط المفتاح الجديد بعد النشر ⇒ هو الأحدث، والقديم يُحذف وحده
            ['key' => 'meeting_autoclose_hours', 'value' => '3', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'meeting_autoclose_minutes', 'value' => '45', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration->up();

        $this->assertSame('480', Setting::get('session_stale_minutes'));
        $this->assertSame('0', Setting::get('consult_reschedule_notice_minutes'));
        $this->assertSame('45', Setting::get('meeting_autoclose_minutes'));
        $this->assertNull(Setting::get('consult_autoclose_minutes'), 'ما لم يُحفظ يبقى على افتراضه');
        $this->assertSame(0, DB::table('settings')->where('key', 'like', '%_hours')->count());

        $migration->down();

        $this->assertSame('8', Setting::get('session_stale_hours'));
        $this->assertSame('1', Setting::get('meeting_autoclose_hours'), '٤٥ دقيقة ⇒ أدنى ما كان يقبله المفتاح القديم');
        $this->assertSame(0, DB::table('settings')->where('key', 'like', '%_minutes')->whereIn('key', [
            'session_stale_minutes', 'consult_reschedule_notice_minutes', 'meeting_autoclose_minutes',
        ])->count());
    }
}
