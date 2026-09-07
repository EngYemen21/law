<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **كلّ بطاقةٍ في عمودٍ واحد — لا صفرٌ ولا اثنان.**
 *
 * كانت أعمدة الكانبان الخمسة مرشِّحاتٍ مستقلّة، فوقع عطلان متعاكسان في الشاشة الواحدة:
 *
 * **ثقبٌ يبتلع.** `'محالة للمحامي'` بلا موعدٍ ليست في أيّ عمود — الثالث يسرد حالات
 * المراجعة دونها، والرابع يشترط `startsAt` أو جلسةً جارية. فاستشارةٌ أُحيلت ولم
 * يُحدَّد موعدُها **تختفي من المسار كلّه**؛ وهي الحالة التي يُفرد لها `refer` إشعاراً
 * خاصّاً — أي أنّ النظام يعرفها ويعالجها ثمّ يُخفيها عن المدير.
 *
 * **وتكرارٌ يضاعف.** «جاهزة للمحامي» ولها موعدٌ تُعرض في الثالث والرابع معاً، فمجموع
 * العدّادات يتجاوز الإجمالي المكتوب فوق الشاشة نفسها.
 *
 * والحارس **بنيويّ**: يمسح الشاشة فيمنع عودة المرشِّحات المستقلّة، ثمّ يفحص القسمة
 * على حالاتٍ حقيقيّةٍ من القاعدة.
 */
class AdminConsultPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function screen(): string
    {
        return (string) preg_replace(
            '#/\*.*?\*/|//[^\n]*#su',
            '',
            (string) file_get_contents(resource_path('js/pages/admin/consults.tsx'))
        );
    }

    /** **الحارس الأثمن:** الأعمدة تقرأ قسمةً واحدة لا تُرشِّح كلٌّ لنفسها. */
    public function test_the_kanban_is_a_single_partition(): void
    {
        $code = $this->screen();

        $this->assertStringContainsString(
            'kanbanColumnOf(c) === c0.id',
            $code,
            'العمود يُشتقّ من قسمةٍ واحدة'
        );

        // ولا مرشِّحَ مستقلٌّ يعود: كانت خمسةً، كلٌّ يقرأ الحالة بنفسه. والآن واحدٌ
        // يقرأ القسمة — فأيّ سادسٍ يُضاف يُسقط الحارس.
        $this->assertSame(
            1,
            substr_count($code, 'items: filteredItems.filter('),
            'المرشِّحات المستقلّة هي أصل الثقب والتكرار'
        );
    }

    /**
     * **والقسمة كاملةٌ متعامدة:** كلّ حالةٍ في الكتالوج تنتمي إلى عمودٍ واحد.
     *
     * تُحاكى دالّة `kanbanColumnOf` بترتيب أولويّتها نفسه؛ فلو غُيّر أحدهما دون الآخر
     * سقط الاختبار.
     */
    public function test_every_catalogued_status_lands_in_exactly_one_column(): void
    {
        $columnOf = function (string $status, ?string $session, bool $hasStart): string {
            if (in_array($status, Consult::TERMINAL_STATUSES, true)) {
                return 'completed';
            }
            if ($session === 'جلسة جارية' || $status === 'قيد الاستشارة') {
                return 'active_sessions';
            }
            if (in_array($status, ['بانتظار التسعير', 'بانتظار السداد'], true)) {
                return 'pre_session';
            }
            if (in_array($status, ['بانتظار تحديد الموعد', 'جديدة'], true)) {
                return 'scheduling';
            }

            return $hasStart ? 'active_sessions' : 'review';
        };

        $columns = ['pre_session', 'scheduling', 'review', 'active_sessions', 'completed'];

        foreach (Consult::STATUSES as $status) {
            foreach ([null, 'بانتظار الجلسة', 'جلسة جارية', 'منتهية', 'لم تُعقد'] as $session) {
                foreach ([false, true] as $hasStart) {
                    $col = $columnOf($status, $session, $hasStart);

                    $this->assertContains(
                        $col,
                        $columns,
                        "«{$status}» تسقط خارج الأعمدة الخمسة"
                    );
                }
            }
        }
    }

    /** **والثقبُ الأصليّ مسدود:** محالةٌ للمحامي بلا موعدٍ لها عمود. */
    public function test_a_referred_consult_without_an_appointment_is_visible(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-PIPE-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'محالة للمحامي',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-green',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            // لا `starts_at` — وهي الحالة التي يُفرد لها `refer` إشعاراً خاصّاً
        ]);

        $res = $this->actingAs($admin)->get('/admin/consults');
        $res->assertOk();

        $cards = collect($res->viewData('page')['props']['consults'] ?? []);
        $card = $cards->firstWhere('status', 'محالة للمحامي');

        $this->assertNotNull($card, 'الصفّ يصل الشاشة أصلاً');
        $this->assertNull($card['startsAt'], 'بلا موعدٍ — وهو شرط العطل');

        // القسمة تضعها في «قيد المعالجة والإحالة» لا في العدم
        $this->assertNotContains($card['status'], Consult::TERMINAL_STATUSES);
        $this->assertNotContains($card['status'], Consult::PRE_SESSION_STATUSES);
    }

    /**
     * **وقاموس الأولويّة واحدٌ في الشاشة الواحدة.**
     *
     * كان المرشِّح يعرض «عادية» — أولويّةَ **تذكرة** يرفضها هذا الخادم — ويُغفل
     * «منخفضة» التي تكتبها أزرارُ الدرج في الشاشة نفسها. فما تضبطه بيدك لا تستطيع
     * تصفيتَه، وما تصفّيه لا يقع.
     */
    public function test_the_priority_filter_matches_what_the_screen_writes(): void
    {
        $code = $this->screen();

        $this->assertStringNotContainsString('value="عادية"', $code, '«عادية» أولويّةُ تذكرة لا استشارة');
        $this->assertSame(2, substr_count($code, 'CONSULT_PRIORITIES.map'), 'المرشِّح والأزرار من كتالوجٍ واحد');
        $this->assertSame(['عالية', 'متوسطة', 'منخفضة'], Consult::PRIORITIES);
    }

    /** **والحدّ الأدنى ريالٌ واحد في كلّ مدخل** — لا في مدخلين من ثلاثة. */
    public function test_the_price_floor_holds_in_the_drawer_too(): void
    {
        $code = $this->screen();

        $this->assertStringNotContainsString('priceNum < 0', $code, 'كان في هذه الشاشة موضعان للصفر فاتا الدفعة الأولى');
        $this->assertSame(2, substr_count($code, 'priceNum < 1'), 'الدرج والمودال كلاهما');

        // **والمدخل نفسه لا يدعو إلى الصفر:** المنطق كان يمنع و`min="0"` يقول للمتصفّح
        // إنّ الصفر مقبول — فالسهم ينزل إليه ويُرفض الإرسال بلا سببٍ ظاهر.
        foreach (['js/pages/admin/consults.tsx', 'js/pages/admin/consult-requests.tsx'] as $rel) {
            $this->assertStringNotContainsString(
                'min="0"',
                (string) file_get_contents(resource_path($rel)),
                "{$rel}: مدخلُ سعرٍ يعلن الصفر حدّاً أدنى"
            );
        }
    }

    /** **وزرّ الإلغاء موصولٌ بمسارٍ قائم** — لا زرٌّ يفشل دائماً. */
    public function test_the_cancel_button_targets_a_real_route(): void
    {
        $this->assertStringContainsString('cancel-request', $this->screen());

        $routes = (string) file_get_contents(base_path('routes/web.php'));
        $this->assertStringContainsString(
            "'/consults/{consult}/cancel-request'",
            $routes,
            'المسار قائمٌ ومحروسٌ بصلاحيّة'
        );
    }
}
