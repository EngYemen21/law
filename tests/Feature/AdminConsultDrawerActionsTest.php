<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * درج 360° في «إدارة الاستشارات»: **لا يُدعى المستخدم إلى فعلٍ يردّه الخادم.**
 *
 * كانت كتلتا «إعادة إسناد المستشار» و«تشغيل التحليل» بلا أيّ شرطِ حالة، بينما
 * `refer` يردّ ٤٢٢ في ثلاث حالات (جلسةٌ منعقدة · انتهت أو أُلغيت · ما زالت في دورة
 * الحجز) و`analyze` يردّ ٤٢٢ على المنتهية والملغاة. وقيسَ حيّاً على القاعدة:
 * **اثنتان من ثلاث استشارات** كانتا تعرضان زرّ الإسناد وتردّان ٤٢٢.
 *
 * وزرّ «إلغاء الطلب» كان مشروطاً بغير شرط الخادم (`TERMINAL` بدل `PRE_SESSION`)،
 * وأيقونته `x` لا وجود لها في `ICON_PATHS` فيُصيَّر `<svg>` فارغ.
 */
class AdminConsultDrawerActionsTest extends TestCase
{
    use RefreshDatabase;

    private function consult(array $extra = []): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-DRW-'.random_int(100, 999),
            'subject' => 'نزاع تجاري',
            'type' => 'استشارة',
            'channel' => 'مرئية',
            'status' => 'منتهية',
            'session' => 'منتهية',
            'tone' => 'b-grey',
            'lawyer' => 'مستشار',
        ], $extra));
    }

    // ————— ١ · الخادم يردّ فعلاً في الحالات الثلاث —————

    public function test_refer_is_refused_on_a_closed_consult(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consult(['status' => 'منتهية']);

        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/refer", ['lawyer_id' => $lawyer->id])
            ->assertStatus(422);
    }

    public function test_refer_is_refused_during_a_live_session_and_inside_the_booking_cycle(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $live = $this->consult(['status' => 'قيد الاستشارة', 'session' => 'جلسة جارية']);
        $this->actingAs($admin)
            ->post("/admin/consults/{$live->id}/refer", ['lawyer_id' => $lawyer->id])
            ->assertStatus(422);

        $booking = $this->consult(['status' => 'بانتظار التسعير', 'session' => 'بانتظار الجلسة']);
        $this->actingAs($admin)
            ->post("/admin/consults/{$booking->id}/refer", ['lawyer_id' => $lawyer->id])
            ->assertStatus(422);
    }

    public function test_analyze_is_refused_on_a_closed_consult(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->consult(['status' => 'ملغاة']);

        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/analyze")
            ->assertStatus(422);
    }

    // ————— ٢ · والشاشة لا تعرض ما يُردّ —————

    public function test_the_drawer_hides_every_action_the_server_refuses(): void
    {
        $ui = file_get_contents(resource_path('js/pages/admin/consults.tsx'));

        // إعادة الإسناد مشروطةٌ بحرّاس refer الثلاثة — عبر مساعدٍ واحد يطابقها
        $this->assertStringContainsString('function referBlockReason(', $ui);
        $this->assertStringContainsString('{referBlocked ? (', $ui);
        $this->assertStringContainsString("c.session === 'جلسة جارية'", $ui);
        $this->assertStringContainsString('CONSULT_CLOSED_STATUSES.includes(c.status)', $ui);
        $this->assertStringContainsString('CONSULT_BOOKING_STATUSES.includes(c.status)', $ui);

        // والتحليل لا يُعرض على ملفٍّ انتهى
        $this->assertStringContainsString(
            '{!CONSULT_CLOSED_STATUSES.includes(drawerConsult.status) && (',
            $ui
        );

        // والإلغاء بشرط الخادم حرفيّاً لا بشرطٍ مجاور
        $this->assertStringNotContainsString(
            '{!CONSULT_TERMINAL_STATUSES.includes(drawerConsult.status) && !drawerConsult.paid && (',
            $ui
        );
        $this->assertStringContainsString(
            '{CONSULT_BOOKING_STATUSES.includes(drawerConsult.status) && !drawerConsult.paid && (',
            $ui
        );
    }

    public function test_no_screen_asks_for_an_icon_that_does_not_exist(): void
    {
        $icons = file_get_contents(resource_path('js/lib/icons.tsx'));

        foreach (['js/pages/admin/consults.tsx'] as $file) {
            $src = file_get_contents(resource_path($file));
            preg_match_all('/Icon name="([a-z-]+)"/u', $src, $m);
            foreach (array_unique($m[1]) as $name) {
                // `<svg>` فارغٌ بلا خطأ: الزرّ يظهر بلا أيقونة ولا يُنبَّه أحد
                $this->assertMatchesRegularExpression(
                    '/^\s+'.preg_quote($name, '/').':/mu',
                    $icons,
                    "{$file}: الأيقونة «{$name}» غير معرّفة في ICON_PATHS"
                );
            }
        }
    }

    // ————— ٣ · ترتيبٌ يطابق ما يَعِد به العنوان —————

    public function test_audit_entries_carry_a_sortable_key(): void
    {
        $consult = $this->consult();
        $consult->logAudit('الإدارة', 'التسعير', '—', '٥٠٠');
        $consult->save();

        $entry = $consult->fresh()->audit[0];

        // `time` للعرض بصيغة ١٢ ساعة — لا تُفرز لفظياً
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/\d{2} \d{2}:\d{2} (ص|م)$#u', $entry['time']);
        // و`at` مفتاح الفرز
        $this->assertArrayHasKey('at', $entry);
        $this->assertNotNull($entry['at']);
        $this->assertSame(now()->toDateString(), substr((string) $entry['at'], 0, 10));
    }

    public function test_the_screen_sorts_by_the_real_key_not_by_the_display_text(): void
    {
        $ui = file_get_contents(resource_path('js/pages/admin/consults.tsx'));

        // سجلّ التدقيق: على `at` لا على `time` النصّيّ
        $this->assertStringNotContainsString("String(b.time ?? '').localeCompare", $ui);
        $this->assertStringContainsString('new Date(b.at ?? 0).getTime() - new Date(a.at ?? 0).getTime()', $ui);

        // والأجندة تفرز فعلاً على `startsAt` — كان العنوان يَعِد بترتيبٍ زمنيّ بلا فرز
        $this->assertStringContainsString('new Date(a.startsAt).getTime() - new Date(b.startsAt).getTime()', $ui);

        // ولا نسخةَ يدويّة من كتالوج دورة الحجز
        $this->assertStringNotContainsString(
            "['بانتظار التسعير', 'بانتظار السداد', 'بانتظار تحديد الموعد'].includes(c.status)",
            $ui
        );
    }

    // ————— ٤ · الملفّ المنتهي ليس بلا أفعال —————

    public function test_admin_can_approve_the_summary_and_create_tasks_after_the_session(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->consult([
            'status' => 'منتهية',
            'session' => 'منتهية',
            'summary' => 'اتّفق الطرفان على تعديل بند التسليم وصياغة ملحقٍ للعقد.',
            'decisions' => ['صياغة ملحق تعديل العقد', 'مراجعة بند الغرامة'],
        ]);

        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/summary/approve")
            ->assertRedirect();
        $this->assertNotNull($consult->fresh()->summary_approved_at, 'الاعتماد فعلٌ متاحٌ للإدارة على المنتهية');

        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/tasks")
            ->assertRedirect();
        $this->assertTrue((bool) $consult->fresh()->tasks_created);

        // والاعتماد نهائيّ: الثانية مرفوضة
        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/summary/approve")
            ->assertStatus(422);
    }

    public function test_the_drawer_offers_the_post_session_actions(): void
    {
        $ui = file_get_contents(resource_path('js/pages/admin/consults.tsx'));

        // كتلة «حصيلة الجلسة» تظهر عند ختم الجلسة أو انتهاء الملفّ.
        // **الشرط من أوّله**: فحصُ الاسم وحده ينجو من `{(false && …)` — جرّبتُه فنجا.
        $this->assertStringContainsString(
            '{(CONSULT_SESSION_ENDED.includes(drawerConsult.session)',
            $ui,
            'كتلة حصيلة الجلسة مُعطَّلة أو مشروطةٌ بغير حالة الجلسة'
        );
        $this->assertStringContainsString('triggerApproveSummary(drawerConsult)', $ui);
        $this->assertStringContainsString('triggerCreateTasks(drawerConsult)', $ui);

        // ولا تُعرض دعوةٌ إلى اعتمادٍ وقع، ولا إلى مهامٍّ أُنشئت
        $this->assertStringContainsString('drawerConsult.summaryApproved ? (', $ui);
        $this->assertStringContainsString('drawerConsult.tasksCreated ? (', $ui);

        // والرفض يُسمَع في كليهما
        $this->assertSame(
            2,
            substr_count($ui, "onError: (err) => toast(`⚠️ \${Object.values(err)[0] || 'تعذّر"),
            'كلا الفعلين الجديدين يعرض سبب الرفض'
        );
    }
}
