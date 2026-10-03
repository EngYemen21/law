<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **استشارةٌ انتهت جلستها بلا ملخّص — خمسة عيوبٍ ظهرت على CN-2026-1032** (ملاحظة المالك 2026-10-04).
 *
 * ثبتت في المتصفّح على نظيرٍ لها في قاعدة التجربة:
 * ١) المحرّر لا يظهر إلّا لملخّصٍ قائم — فيرى المحامي رسالة العميل «قيد المراجعة…» ولا يكتب شيئاً.
 * ٢) خطّ الاستقبال ينتهي عند «جاهزة/محالة للمحامي» — فتقف عنده جلسةٌ منتهية.
 * ٣) «هاتف العميل: —» لغير الهاتفيّة مع أنّ الجوال في حساب العميل.
 * ٤) سبب منع الإسناد يُعاد بناؤه في الواجهة وينقصه «التحليل غير المعتمد»، والملفّ المقفل يعرض نموذجاً معطّلاً.
 * ٥) شارتان «منتهية» متجاورتان (حالة الملفّ وحالة الجلسة).
 */
class ConsultEndedFileFixesTest extends TestCase
{
    use RefreshDatabase;

    private function src(string $rel): string
    {
        return (string) file_get_contents(resource_path($rel));
    }

    private function consult(array $attrs = [], string $phone = '0537434000'): Consult
    {
        $client = User::factory()->create(['role' => Role::Client, 'phone' => $phone]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        return Consult::create($attrs + [
            'user_id' => $client->id, 'ref' => 'CN-FIX-'.uniqid(), 'subject' => 'تتتت', 'channel' => 'مرئية',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id, 'session' => 'منتهية', 'status' => 'منتهية',
            'starts_at' => now()->subHours(2), 'total' => 1, 'paid' => true,
        ]);
    }

    public function test_1_the_summary_editor_shows_for_an_ended_session_without_a_summary(): void
    {
        $ui = $this->src('js/lib/consult-ui.tsx');
        $this->assertStringContainsString('c.summary || (canEditHere && c.sessionEnded) ?', $ui, 'المحرّر لمن يكتب الملخّص ولو لم يُولَّد');
        $this->assertStringContainsString('canApproveSummary && Boolean(c.summary) &&', $ui, 'الاعتماد لنصٍّ محفوظ فقط');

        // والخادم يقبل أوّل ملخّصٍ يكتبه المحامي المسنَد
        $consult = $this->consult();
        $lawyer = User::find($consult->assigned_lawyer_id);
        $this->actingAs($lawyer)->post("/lawyer/consults/{$consult->id}/summary", ['summary_html' => '<p>خلاصة الرأي القانوني للجلسة.</p>'])
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('خلاصة الرأي القانوني', (string) $consult->fresh()->summary);
    }

    public function test_2_the_direct_journey_has_session_and_summary_stages(): void
    {
        $data = $this->src('js/lib/employee-data.ts');
        $this->assertMatchesRegularExpression("/export const CONSULT_FLOW = \\[[^\\]]*'انعقاد الجلسة', 'الملخّص والاعتماد'\\]/u", $data);
        $this->assertStringContainsString("'قيد الاستشارة': 5, 'منتهية': 6", $data, 'المنتهية عند «الملخّص والاعتماد» لا «محالة للمحامي»');
        $this->assertStringContainsString('const currentStage = c.summaryApproved', $this->src('js/lib/consult-ui.tsx'), 'المعتمد ملخّصها تكتمل رحلتها');
    }

    public function test_3_staff_see_the_account_phone_for_a_video_consult(): void
    {
        $this->assertSame('0537434000', $this->consult()->toCard()['phone']);
        $this->assertSame('0555000111', $this->consult(['channel' => 'هاتفية', 'phone' => '0555000111'], '0537434001')->toCard()['phone'], 'جوال الهاتفيّة المحفوظ أوّلاً');
    }

    public function test_4_the_assign_blocker_comes_from_the_refer_guard(): void
    {
        $this->assertNotNull($this->consult(['session' => 'جلسة جارية', 'status' => 'قيد الاستشارة'])->toCard()['assignBlocker'], 'الجلسة الجارية تمنع الإسناد');
        $this->assertNotNull($this->consult(['session' => 'بانتظار الجلسة', 'status' => 'بانتظار اعتماد الموظف'], '0537434002')->toCard()['assignBlocker'], 'التحليل غير المعتمد يمنع الإسناد — كان الزرّ فاعلاً فيردّه الخادم');
        $this->assertNotNull($this->consult([], '0537434003')->toCard()['assignBlocker'], 'المنتهية تمنع الإسناد');
        $this->assertNull($this->consult(['session' => 'بانتظار الجلسة', 'status' => 'جاهزة للمحامي'], '0537434004')->toCard()['assignBlocker'], 'الجاهزة تُسند');

        $this->assertStringContainsString('const referBlocked = c.assignBlocker ?? null;', $this->src('js/lib/consult-ui.tsx'));
        $drawer = $this->src('js/pages/employee/consults.tsx');
        $this->assertStringContainsString("{isClosed ? (\n                      <p", $drawer, 'الملفّ المقفل قراءةٌ لا نموذج');
        $this->assertStringNotContainsString('disabled={isClosed || Boolean(drawerConsult.assignBlocker)}', $drawer);
    }

    public function test_5_the_session_badge_hides_when_it_repeats_the_status(): void
    {
        $this->assertStringContainsString('{c.session && c.session !== c.status ? <Badge text={c.session}', $this->src('js/lib/consult-ui.tsx'));
    }
}
