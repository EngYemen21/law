<?php

namespace Tests\Feature;

use App\Models\Consult;
use App\Models\Invoice;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RichDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **البذّار لا يُولّد حالةً لا يبلغها النظام.**
 *
 * صفٌّ مستحيل يجعل الشاشة تعرض هراءً وتبدو معطوبة وهي سليمة — فيُطارَد عطلٌ في
 * الشيفرة سببُه بيانات. وقياسٌ على قاعدة التطوير أظهر أربعة أنواع:
 *
 * - **١٠ استشارات مدفوعة بلا فاتورة** — والمسار الحيّ يُنشئها **داخل المعاملة نفسها**
 *   التي تكتب `priced_at`. فالعميل يرى «مدفوعة» ولا يجد فاتورةً يفتحها.
 * - **٣ مدفوعة بلا تسعير** — رحلةٌ مقلوبة: «سُدِّد» مضيء و«سُعِّر» مطفأ.
 * - **`'مؤكد'`** — حالةٌ تُكتب على الموعد لا على الاستشارة؛ لا يبلغها أيّ مسار.
 * - **رابط Zoom بلا `meet_id`** — والويبهوك يُوجَّه بالمعرّف، فلا يصل الصفَّ حدثٌ قطّ.
 */
class SeederProducesReachableStatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // `RichDemoSeeder` يفترض الحسابات الأساسيّة الأربعة (`firstOrFail` على
        // أرقام الهويّة)، وهي ما يبذره `DatabaseSeeder` — فالترتيب جزءٌ من العقد.
        $this->seed(DatabaseSeeder::class);
        $this->seed(RichDemoSeeder::class);
    }

    /** **الحارس الأثمن:** كلّ استشارةٍ مبذورة في حالةٍ يبلغها النظام. */
    public function test_the_seeder_only_produces_states_a_live_path_can_reach(): void
    {
        $impossible = [];

        foreach (Consult::all() as $c) {
            if (! in_array($c->status, Consult::STATUSES, true)) {
                $impossible[] = "{$c->ref}: حالةٌ خارج الكتالوج «{$c->status}»";
            }

            if ($c->paid_at !== null && $c->priced_at === null) {
                $impossible[] = "{$c->ref}: مدفوعة بلا تسعير — `markPaid` لا يُبلَغ إلّا بعد `setPrice`";
            }

            if ($c->priced_at !== null && Invoice::where('consult_id', $c->id)->doesntExist()) {
                $impossible[] = "{$c->ref}: مسعَّرة بلا فاتورة — المسار الحيّ يُنشئها في المعاملة نفسها";
            }

            if (filled($c->meet_link) && blank($c->meet_id)) {
                $impossible[] = "{$c->ref}: رابط Zoom بلا معرّف — الويبهوك يُوجَّه بالمعرّف";
            }

            if ($c->summary !== null && $c->summary_approved_at === null && $c->session === 'منتهية') {
                $impossible[] = "{$c->ref}: ملخّصٌ محجوبٌ بلا مسارِ اعتماد";
            }
        }

        $this->assertSame([], $impossible, "بذرةٌ لا يبلغها مسار:\n".implode("\n", $impossible));
    }

    /** والبذرة تُنتج بياناتٍ فعلاً — وإلّا كان الحارس أعلاه فارغاً يمرّ على لا شيء. */
    public function test_the_seeder_actually_produced_consults(): void
    {
        $this->assertGreaterThan(5, Consult::count());
        $this->assertGreaterThan(0, Consult::whereNotNull('paid_at')->count(), 'ومنها مدفوعة');
    }

    /** **ولا تُبذَر حالةٌ ميتة** — `'مؤكد'` تُكتب على الموعد لا على الاستشارة. */
    public function test_no_consult_is_seeded_in_a_status_only_an_appointment_holds(): void
    {
        $this->assertSame(
            0,
            Consult::where('status', 'مؤكد')->count(),
            '«مؤكد» حالةُ موعدٍ — والاستشارة تُضبط «جديدة» بعد الحجز'
        );
    }
}
