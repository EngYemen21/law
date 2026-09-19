<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **قاعدةٌ واحدة عبر المنظومة (قرار المالك 2026-09-08):**
 * كلُّ حقلٍ يُحرَّر ثمّ يُعتمد اعتماداً نهائيّاً — ملخّصُ التذكرة، ملخّصُ الاستشارة،
 * ملخّصُ الاجتماع ومحضرُه — **الحفظُ قبل الاعتماد مسوّدةٌ تُعدَّل، وبعده لا يُعدَّل**.
 * ومزامنةُ Zoom تتبع القاعدة نفسها: لا تُحدَّث بياناتُ جلسةٍ اعتُمد ملخّصُها.
 *
 * وكان في المنظومة ثقبٌ واحد: `Lawyer\TicketController::updateSummary` مكشوفاً بينما
 * `rerunSummary` بجواره محروسٌ بـ`isApproved()` — أي أنّ إعادةَ التوليد ممنوعةٌ بعد
 * الاعتماد والكتابةَ اليدويّة مسموحة، وهي الأخطر.
 *
 * ومعها: **لا تقنيعَ على الإدارة العليا** — كانت خمسةُ متحكّماتٍ إداريّة تعرض
 * «ع••••ه (مشفّر)» لمن يملك الملفّ كلَّه ويُنزّل نصّه التفريغيّ صريحاً.
 */
class ApprovalLocksEveryEditorTest extends TestCase
{
    use RefreshDatabase;

    // ————— ١ · ملخّص التذكرة: الثقب الذي كان مفتوحاً —————

    public function test_an_approved_ticket_summary_cannot_be_edited(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'TK-LOCK-1', 'type' => 'استشارة',
            'subject' => 'نزاع', 'status' => 'جديدة', 'assigned_lawyer_id' => $lawyer->id,
        ]);
        $ticket->summary()->create([
            'case_summary' => 'الملخّص المعتمد رسميًّا.',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->actingAs($lawyer)
            ->post("/lawyer/summary/{$ticket->number}", ['case_summary' => 'نصّ بديل بعد الاعتماد'])
            ->assertStatus(422);

        $this->assertSame('الملخّص المعتمد رسميًّا.', $ticket->fresh()->summary->case_summary);
    }

    public function test_a_draft_ticket_summary_is_still_editable(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'TK-LOCK-2', 'type' => 'استشارة',
            'subject' => 'نزاع', 'status' => 'جديدة', 'assigned_lawyer_id' => $lawyer->id,
        ]);
        $ticket->summary()->create(['case_summary' => 'مسودّة أولى', 'status' => 'draft']);

        $this->actingAs($lawyer)
            ->post("/lawyer/summary/{$ticket->number}", ['case_summary' => 'مسودّة ثانية'])
            ->assertRedirect();

        $this->assertSame('مسودّة ثانية', $ticket->fresh()->summary->case_summary);
    }

    // ————— ٢ · القاعدة نفسها في الاستشارة والاجتماع —————

    public function test_approved_consult_and_meeting_editors_are_locked(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-LOCK-1', 'subject' => 'نزاع',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'منتهية',
            'session' => 'منتهية', 'tone' => 'b-grey', 'lawyer' => 'مستشار',
            'summary' => 'ملخّص معتمد', 'summary_approved_at' => now(),
            'meet_id' => '91234567890',
        ]);

        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/summary", ['summary' => 'بديل'])
            ->assertStatus(422);
        // ومزامنةُ Zoom تتبع القاعدة
        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/zoom-sync")
            ->assertStatus(422);
        $this->assertSame('ملخّص معتمد', $consult->fresh()->summary);

        $meeting = Meeting::create([
            'ref' => 'M-LOCK-9', 'title' => 'اجتماع', 'when_label' => 'أمس', 'status' => 'منتهٍ',
            'approve' => 'معتمد', 'sum_approved' => true, 'meet_id' => '91234567891',
            'summary' => 'ملخّص معتمد', 'minutes' => 'محضر معتمد',
        ]);

        $this->actingAs($admin)
            ->post("/admin/meetings/{$meeting->id}/summary", ['summary' => 'بديل'])
            ->assertStatus(422);
        $this->actingAs($admin)
            ->post("/admin/meetings/{$meeting->id}/minutes", ['minutes' => 'بديل'])
            ->assertStatus(422);
        $this->actingAs($admin)
            ->post("/admin/meetings/{$meeting->id}/zoom-sync")
            ->assertStatus(422);
    }

    // ————— ٣ · لا تقنيعَ على الإدارة العليا —————

    /** وتوسّع القرار (2026-09-11): لا تقنيع على المحامي والموظّف كذلك. */
    public function test_no_staff_role_sees_a_masked_client_name(): void
    {
        foreach ([Role::Admin, Role::Lawyer, Role::Employee] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->assertSame('شركة الأفق للتجارة', Ticket::maskClient('شركة الأفق للتجارة'), $role->value);
        }
    }

    public function test_the_shared_screens_mask_by_role_not_unconditionally(): void
    {
        $perms = file_get_contents(resource_path('js/lib/permissions.ts'));
        $this->assertStringContainsString('export function useMasker()', $perms);
        $this->assertStringContainsString('props.auth?.user?.isSuper', $perms);

        foreach (['js/lib/consult-ui.tsx', 'js/lib/meeting-ui.tsx'] as $file) {
            $src = file_get_contents(resource_path($file));
            $this->assertStringContainsString('useMasker()', $src, "{$file}: يُقنّع بلا نظرٍ إلى الدور");
        }
    }

    // ————— ٤ · ما أُزيل بقرارك —————

    public function test_the_manual_take_button_is_gone(): void
    {
        foreach (['js/lib/consult-ui.tsx', 'js/pages/employee/consults.tsx'] as $file) {
            $this->assertStringNotContainsString(
                'استلام الاستشارة',
                file_get_contents(resource_path($file)),
                "{$file}: زرّ الاستلام أُزيل بقرار المالك"
            );
        }
    }

    public function test_priority_cannot_be_changed_on_a_closed_consult(): void
    {
        $ui = file_get_contents(resource_path('js/lib/consult-ui.tsx'));

        $this->assertStringContainsString(
            'disabled={busy || CONSULT_CLOSED_STATUSES.includes(c.status)}',
            $ui,
            'الأولويّة أداةُ ترتيبِ عملٍ قائم — لا تُبدَّل على ملفٍّ خرج من الطابور'
        );
    }
}
