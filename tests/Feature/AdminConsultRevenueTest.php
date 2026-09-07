<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **الإيراد المحصَّل ما سُدِّد — لا ما انتهى.**
 *
 * كان مؤشّر «إيرادات محصلة» في لوحة الإدارة يجمع `paid || TERMINAL`، و`TERMINAL` يضمّ
 * **«ملغاة»** و**«لم يحضر»**. فطلبٌ أُلغي وله تسعيرٌ سابق يُحتسب إيراداً — و`cancelRequest`
 * نفسه يُقرّ باحتمال الإلغاء بعد السداد. **والصفّ نفسه يستوفي شرط «المعلق»** فيُعرض
 * مرّتين في البطاقة الواحدة: محصَّلاً ومعلَّقاً معاً.
 *
 * وزاده سوءاً أنّ الشاشة تحسب الإيراد بتعريفٍ ثانٍ في «توزيع القنوات» (`c.paid` وحدها)
 * تحت العنوان نفسه — فرقمان مختلفان لاسمٍ واحد في شاشةٍ واحدة.
 *
 * **وحارسٌ بنيويّ لا حسابيّ:** يمسح الشاشة فيُسقط أيّ مجموعٍ ماليّ يعتمد الحالة بدل
 * `paid`، فلا يعود الصدق معتمداً على تذكّر من يكتب المؤشّر التالي.
 */
class AdminConsultRevenueTest extends TestCase
{
    use RefreshDatabase;

    private function screen(): string
    {
        $src = (string) file_get_contents(resource_path('js/pages/admin/consults.tsx'));

        // تُسقط التعليقات: تصف العطل بنصّه فتُشعل الحارس على شرحه
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#su', '', $src);
    }

    /** لا مجموعَ ماليّ يقيس الحصيلة بالحالة. */
    public function test_no_revenue_total_is_derived_from_status(): void
    {
        $code = $this->screen();

        $this->assertStringNotContainsString(
            'c.paid || CONSULT_TERMINAL_STATUSES.includes(c.status)',
            $code,
            'الملغاة ليست إيراداً محصَّلاً'
        );

        // المجموع الوحيد المسموح مصدره `paid`
        $this->assertMatchesRegularExpression(
            '/paidRevenue\s*=\s*allItems\s*\.filter\(\(c\)\s*=>\s*c\.paid\)/u',
            $code,
            'المحصَّل يُقاس بـ`paid` وحدها'
        );
    }

    /** والمعلَّق يُقيَّد بما سُعِّر فعلاً — لا بسعرٍ مقترحٍ لم يُقرَّر. */
    public function test_pending_revenue_counts_only_decided_prices(): void
    {
        $this->assertStringContainsString('!c.paid && c.priced', $this->screen());
    }

    /**
     * **ولا يُعَدّ الصفّ الواحد مرّتين.**
     *
     * المحصَّل والمعلَّق يجب أن يكونا متعامدين: `paid` و`!paid`. يُفحص سلوكيّاً على
     * صفوفٍ حقيقيّة بمحاكاة التعبيرين.
     */
    public function test_collected_and_pending_never_overlap(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $rows = [
            ['ملغاة', true, 500],    // ملغاة ومسعَّرة وغير مسدَّدة
            ['منتهية', true, 600],   // منتهية ومسدَّدة
            ['بانتظار السداد', true, 700],
        ];

        $made = [];
        foreach ($rows as $i => [$status, $priced, $total]) {
            $made[] = Consult::create([
                'user_id' => $client->id, 'ref' => 'CN-REV-'.$i.'-'.uniqid(),
                'subject' => 'نزاع', 'type' => 'استشارة', 'channel' => 'مرئية',
                'status' => $status, 'session' => 'بانتظار الجلسة', 'tone' => 'b-grey',
                'lawyer' => 'مستشار', 'total' => $total,
                'priced_at' => $priced ? now() : null,
                'paid_at' => $status === 'منتهية' ? now() : null,
            ]);
        }

        $cards = array_map(fn (Consult $c) => $c->toCard(), $made);

        $collected = array_filter($cards, fn ($c) => $c['paid']);
        $pending = array_filter($cards, fn ($c) => ! $c['paid'] && $c['priced'] && $c['total'] > 0);

        $this->assertCount(1, $collected, 'المسدَّدة وحدها');
        $this->assertSame(600, (int) reset($collected)['total']);

        $this->assertSame(
            [],
            array_intersect_key($collected, $pending),
            'لا صفَّ يُعَدّ محصَّلاً ومعلَّقاً معاً'
        );

        // والملغاة غير المسدَّدة تبقى في المعلَّق لا في المحصَّل — دينٌ لا إيراد
        $this->assertCount(2, $pending);
    }
}
