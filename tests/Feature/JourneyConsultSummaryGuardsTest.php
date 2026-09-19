<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Ai\AiReviewOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **ملخّص الجلسة: لا يُعتمد ما لم ينعقد، ولا تبقى قراراتُ نصٍّ تغيّر، ولا يعتمده الموظّف**
 * (خطّة إعادة البناء 2026-09-14، الدفعة ٢ — ع٢٢ · ع٢٣ · ش٢).
 */
class JourneyConsultSummaryGuardsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->client = User::factory()->create(['role' => Role::Client]);
    }

    private function consult(array $extra = []): Consult
    {
        return Consult::create(array_merge([
            'user_id' => $this->client->id, 'ref' => 'CN-SG-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'منتهية', 'session' => 'منتهية',
            'lawyer' => 'مستشار', 'summary' => 'ما دار في الجلسة: اتُّفق على مراجعة العقد.',
        ], $extra));
    }

    private function clientNotifications(): int
    {
        return UserNotification::where('user_id', $this->client->id)->count();
    }

    // ── ع٢٢: اعتماد «ملخّص جلسة» لاستشارة لم تنعقد ──

    public function test_a_summary_for_a_session_that_never_took_place_cannot_be_approved(): void
    {
        $consult = $this->consult(['status' => 'جديدة', 'session' => 'بانتظار الجلسة']);

        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/summary/approve")->assertStatus(422);

        $this->assertNull($consult->fresh()->summary_approved_at);
        $this->assertSame(0, $this->clientNotifications(), 'لا «اعتُمد ملخّص استشارتك» لجلسةٍ لم تنعقد');
    }

    public function test_a_no_show_summary_cannot_be_approved(): void
    {
        $consult = $this->consult(['status' => 'لم يحضر', 'session' => 'لم تُعقد']);

        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/summary/approve")->assertStatus(422);

        $this->assertNull($consult->fresh()->summary_approved_at);
    }

    /** والبوّابة الثانية (صندوق المراجعة) تسلك الكاتب الوحيد نفسه فتلتزم الحارس نفسه. */
    public function test_the_review_inbox_path_refuses_an_unheld_session_too(): void
    {
        $consult = $this->consult(['status' => 'جديدة', 'session' => 'بانتظار الجلسة']);

        $this->assertFalse(AiReviewOutcome::approveConsultSummary($consult, $this->admin));
        $this->assertNull($consult->fresh()->summary_approved_at);
    }

    public function test_the_summary_of_a_held_session_is_still_approved(): void
    {
        $consult = $this->consult();

        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/summary/approve")->assertRedirect();

        $this->assertNotNull($consult->fresh()->summary_approved_at);
    }

    // ── ع٢٣: القرارات لا تتبع تحرير الملخّص ──

    public function test_editing_the_summary_drops_decisions_extracted_from_the_old_text(): void
    {
        $consult = $this->consult(['decisions' => ['قرار من النصّ القديم: رفع دعوى فوراً']]);

        $this->actingAs($this->admin)
            ->post("/admin/consults/{$consult->id}/summary", ['summary' => 'النصّ المحرَّر: لا دعوى، تفاوضٌ أوّلاً.'])
            ->assertRedirect();

        $this->assertSame([], $consult->fresh()->decisions ?? [], 'قرارات النصّ القديم لا تبقى تحت نصٍّ جديد');

        $this->actingAs($this->admin)->post("/admin/consults/{$consult->id}/summary/approve")->assertRedirect();

        $this->assertNotContains(
            'قرار من النصّ القديم: رفع دعوى فوراً',
            $consult->fresh()->toClientCard()['decisions'],
            'العميل لا يقرأ قراراً استُخرج من نصٍّ غيّره المحامي'
        );
    }

    public function test_saving_the_same_text_keeps_the_decisions(): void
    {
        $consult = $this->consult(['decisions' => ['قرار قائم']]);

        $this->actingAs($this->admin)
            ->post("/admin/consults/{$consult->id}/summary", ['summary' => $consult->summary])
            ->assertRedirect();

        $this->assertSame(['قرار قائم'], $consult->fresh()->decisions);
    }

    // ── ش٢: الموظّف لا يعتمد ملخّص استشارة ──

    public function test_employees_have_no_route_to_approve_a_consult_summary(): void
    {
        $this->assertFalse(Route::has('employee.consults.summary.approve'));

        $employee = User::factory()->create(['role' => Role::Employee]);
        foreach (['استقبال الاستشارات', 'اعتماد/تعديل ملخص الاستشارة'] as $permission) {
            Permission::findOrCreate($permission);
            $employee->givePermissionTo($permission);
        }
        $consult = $this->consult();

        $status = $this->actingAs($employee)->post("/employee/consults/{$consult->id}/summary/approve")->status();

        $this->assertContains($status, [404, 405]);
        $this->assertNull($consult->fresh()->summary_approved_at);
        $this->assertSame(0, $this->clientNotifications());
    }
}
