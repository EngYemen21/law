<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\MeetRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * عزل دعوات الاجتماعات بحسب المُرسِل: كلٌّ يرى دعواته فقط ولا يتصرّف في دعوة غيره؛
 * الإدارة العليا ترى الكل وتتصرّف في الكل (إشراف).
 */
// أوقات متباعدة عمداً: حارس الحجز المزدوج صار يرفض نفس المحامي في نفس الفترة
// (كان صامتاً — MeetInvitationConfirmedTest يحرسه). موضوع هذا الملفّ العزل لا التعارض.
class MeetRequestIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invites_are_isolated_by_sender_in_lists_and_actions(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $senderA = User::factory()->create(['role' => Role::Employee, 'name' => 'مُرسِل أ']);
        $senderB = User::factory()->create(['role' => Role::Employee, 'name' => 'مُرسِل ب']);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        // (أ) يُرسل دعوة → يُختم بمعرّفه
        $this->actingAs($senderA)->post(route('employee.meetreqs.store'), [
            'client_id' => $client->id, 'lawyer_id' => $lawyer->id, 'type' => 'استشارة مرئية', 'service' => 'نزاع',
            'day' => now()->addWeek()->format('Y-m-d'), 'time' => '09:00', 'duration' => 60,
        ])->assertRedirect();

        $req = MeetRequest::firstOrFail();
        $this->assertSame($senderA->id, $req->sent_by_id);

        // القوائم: الموظّفون يرون كلّ دعوات المكتب (قرار المالك 2026-09-14) — والإدارة ترى الكل
        $this->actingAs($senderA)->get(route('employee.meetreqs'))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->has('requests', 1));
        $this->actingAs($senderB)->get(route('employee.meetreqs'))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->has('requests', 1));
        $this->actingAs($admin)->get(route('admin.meetreqs'))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->has('requests', 1));

        // الإجراءات: (ب) ممنوع من إلغاء دعوة (أ)
        $this->actingAs($senderB)->post(route('employee.meetreqs.cancel', $req))->assertForbidden();
        $this->assertDatabaseHas('meet_requests', ['id' => $req->id]);

        // (أ) مسموح له بإلغاء دعوته — «أُلغيت» سجلاً تاريخياً (لا حذف صلب يُخفي الأثر عن العميل)
        $this->actingAs($senderA)->post(route('employee.meetreqs.cancel', $req))->assertRedirect();
        $this->assertSame(MeetRequest::STAGE_CANCELLED, $req->fresh()->stage);
    }

    /** قرار المالك 2026-09-14: الموظّف يرى كلّ دعوات المكتب، والمحامي ما أُسند إليه وحده. */
    public function test_employees_see_all_office_invites_and_lawyers_only_those_assigned_to_them(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $sender = User::factory()->create(['role' => Role::Employee]);
        $colleague = User::factory()->create(['role' => Role::Employee]);
        $assigned = User::factory()->create(['role' => Role::Lawyer]);
        $otherLawyer = User::factory()->create(['role' => Role::Lawyer]);

        $permission = Permission::firstOrCreate(['name' => 'إرسال دعوات الاجتماعات', 'guard_name' => 'web']);
        $assigned->givePermissionTo($permission);
        $otherLawyer->givePermissionTo($permission);

        $this->actingAs($sender)->post(route('employee.meetreqs.store'), [
            'client_id' => $client->id, 'lawyer_id' => $assigned->id, 'type' => 'استشارة مرئية', 'service' => 'نزاع',
            'day' => now()->addWeek()->format('Y-m-d'), 'time' => '12:00', 'duration' => 60,
        ])->assertRedirect();

        $this->actingAs($colleague)->get(route('employee.meetreqs'))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->has('requests', 1));
        $this->actingAs($assigned)->get(route('lawyer.meetreqs'))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->has('requests', 1));
        $this->actingAs($otherLawyer)->get(route('lawyer.meetreqs'))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->has('requests', 0));
    }

    public function test_admin_can_cancel_any_invite(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $sender = User::factory()->create(['role' => Role::Employee]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $this->actingAs($sender)->post(route('employee.meetreqs.store'), [
            'client_id' => $client->id, 'lawyer_id' => $lawyer->id, 'type' => 'استشارة مرئية', 'service' => 'نزاع',
            'day' => now()->addWeek()->format('Y-m-d'), 'time' => '10:30', 'duration' => 60,
        ])->assertRedirect();
        $req = MeetRequest::firstOrFail();

        $this->actingAs($admin)->post(route('admin.meetreqs.cancel', $req))->assertRedirect();
        $this->assertSame(MeetRequest::STAGE_CANCELLED, $req->fresh()->stage);
    }
}
