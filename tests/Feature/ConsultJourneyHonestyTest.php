<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **صفحةُ رحلة الاستشارة لدى الإدارة — عيوبٌ من عائلتنا المعتادة.**
 *
 * أخطرُها قيسَ في المتصفّح يوم 2026-09-08 على `CN-2026-7173`: أولويّتُها «منخفضة»،
 * ومُنتقي الأولويّة يعرض `['عالية','متوسطة','عادية']` — فلا يجد القيمة ويسقط على
 * **أوّل خيار**. فالرأس يقول «منخفضة» والأداة تقول «عالية»، وأيُّ حفظٍ يكتب الخطأ.
 * و«عادية» أولويّةُ تذكرةٍ لا استشارة يردّها الخادم بـ٤٢٢.
 *
 * ويُضخّم كلَّ ذلك أنّ مُساعِد `post` كان **بلا `onError`**: أحدَ عشرَ فعلاً تمرّ به،
 * فكلُّ حارسٍ خادميّ يسقط صامتاً. قيسَ بالنقر: «تحديث بيانات الجلسة من Zoom» ردّ ٤٢٢
 * ولم يظهر على الشاشة شيء.
 */
class ConsultJourneyHonestyTest extends TestCase
{
    use RefreshDatabase;

    private function ui(): string
    {
        return file_get_contents(resource_path('js/lib/consult-ui.tsx'));
    }

    private function consult(array $extra = []): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-JRN-'.uniqid(),
            'subject' => 'نزاع تجاري',
            'type' => 'استشارة',
            'channel' => 'مرئية',
            'status' => 'منتهية',
            'session' => 'منتهية',
            'tone' => 'b-grey',
            'lawyer' => 'مستشار',
        ], $extra));
    }

    // ————— ١ · الأولويّة من الكتالوج، والخادم يردّ ما ليس فيه —————

    public function test_the_priority_picker_uses_the_shared_catalogue(): void
    {
        $ui = $this->ui();

        $this->assertStringNotContainsString(
            "['عالية', 'متوسطة', 'عادية']",
            $ui,
            'نسخةٌ يدويّةٌ من الكتالوج عادت — «عادية» أولويّةُ تذكرة يردّها الخادم'
        );
        $this->assertStringContainsString('{CONSULT_PRIORITIES.map((p) =>', $ui);
    }

    public function test_the_server_refuses_a_priority_outside_its_catalogue(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->consult(['priority' => 'منخفضة']);

        // `validate()` يرمي ValidationException ⇒ تحويلٌ مع أخطاء الجلسة، لا 422
        // (بخلاف `abort(422)` في الحرّاس الأخرى) — والتأكيد يتبع ما يفعله الخادم.
        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/priority", ['priority' => 'عادية'])
            ->assertSessionHasErrors('priority');
        $this->assertSame('منخفضة', $consult->fresh()->priority, 'ولا تُكتب القيمة المرفوضة');

        // و«منخفضة» قيمةٌ صالحةٌ كانت غائبةً عن المنتقي
        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/priority", ['priority' => 'منخفضة'])
            ->assertRedirect();
    }

    // ————— ٢ · الرفضُ يُسمَع —————

    public function test_every_journey_action_reports_the_server_refusal(): void
    {
        $ui = $this->ui();

        $this->assertStringContainsString("?? 'تعذّر تنفيذ الإجراء')),", $ui);
        $this->assertStringContainsString("?? 'تعذّر إنشاء المهامّ')),", $ui);
    }

    // ————— ٣ · لا يُعرض فعلٌ يردّه الخادم —————

    public function test_zoom_sync_is_hidden_where_the_server_refuses_it(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $approved = $this->consult([
            'summary' => 'ملخّص معتمد',
            'summary_approved_at' => now(),
            'meet_id' => '91234567890',
        ]);

        $this->actingAs($admin)->post("/admin/consults/{$approved->id}/zoom-sync")->assertStatus(422);

        $this->assertStringContainsString("c.channel === 'مرئية' && !c.summaryApproved", $this->ui());
    }

    public function test_refer_is_gated_by_the_same_three_server_guards(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $closed = $this->consult(['status' => 'منتهية']);

        $this->actingAs($admin)
            ->post("/admin/consults/{$closed->id}/refer", ['lawyer_id' => $lawyer->id])
            ->assertStatus(422);

        $ui = $this->ui();
        $this->assertStringContainsString('const referBlocked = ', $ui);
        $this->assertStringContainsString('CONSULT_CLOSED_STATUSES.includes(c.status)', $ui);
        $this->assertStringContainsString('CONSULT_BOOKING_STATUSES.includes(c.status)', $ui);
        // ولا إحالةَ بلا اختيارٍ صريح — الخادم يسقط إلى النائب النصّيّ «المستشار القانوني»
        $this->assertStringContainsString('disabled={busy || !lawyerId || !!referBlocked}', $ui);
    }

    public function test_reanalysis_is_hidden_on_a_closed_consult(): void
    {
        $ui = $this->ui();

        $this->assertStringContainsString('const analyzeBlocked = CONSULT_CLOSED_STATUSES.includes(c.status);', $ui);
        $this->assertStringContainsString('{mayAnalyze && !analyzeBlocked && (', $ui);
        // و«حفظ التعديلات» يفرض الخادمُ فيه aiLawyer مطلوباً
        $this->assertStringContainsString('disabled={busy || !lawyerName}', $ui);
    }

    // ————— ٤ · «الفعليّة» تعني مقيسة —————

    public function test_the_measured_duration_comes_from_zoom_not_from_a_column_nobody_writes(): void
    {
        $card = $this->consult(['duration_label' => '00:42', 'duration_sec' => null])->toCard();
        $this->assertArrayHasKey('durationSec', $card);
        $this->assertNull($card['durationSec'], 'ما لم يُقَس يبقى null لا رقماً من البذر');

        $measured = $this->consult(['duration_sec' => 365])->toCard();
        $this->assertSame(365, $measured['durationSec']);

        $ui = $this->ui();
        $this->assertStringNotContainsString('<b>{c.duration}</b>', $ui);
        $this->assertStringContainsString('const measured = c.durationSec != null && c.durationSec > 0', $ui);
    }

    // ————— ٥ · الطابع لا يلتبس، والسنتينل لا يُعرض تخصّصاً —————

    public function test_the_audit_stamp_prefers_the_iso_key(): void
    {
        $ui = $this->ui();

        $this->assertStringContainsString('function fmtAuditTime(a: AuditEntry): string {', $ui);
        $this->assertStringContainsString('{fmtAuditTime(a)}', $ui);
        $this->assertStringNotContainsString('fontSize: 11 }}>{a.time}</span>', $ui);
    }

    public function test_the_all_departments_sentinel_is_not_shown_as_a_specialty(): void
    {
        $this->assertStringContainsString(
            "(c.specialty && c.specialty !== 'كل الأقسام') ? c.specialty : c.type",
            $this->ui()
        );
    }

    public function test_the_server_does_not_ask_a_consult_for_meeting_minutes(): void
    {
        // الرسالة في شروط الاعتماد الموحّدة (`Consult::summaryApprovalBlocker`) التي يقرؤها المسار
        $src = file_get_contents(app_path('Models/Consult.php'));

        // الاستشارة لها ملخّصٌ وتدوينُ جلسة — لا محضر
        $this->assertStringNotContainsString('دوّن محضر الجلسة أو اكتب التقرير', $src);
        $this->assertStringContainsString('دوّن تدوين الجلسة أو اكتب التقرير', $src);
    }
}
