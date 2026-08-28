<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\MeetRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

        // القوائم: (أ) يرى دعوته، (ب) لا يرى شيئًا، الإدارة ترى الكل
        $this->actingAs($senderA)->get(route('employee.meetreqs'))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->has('requests', 1));
        $this->actingAs($senderB)->get(route('employee.meetreqs'))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->has('requests', 0));
        $this->actingAs($admin)->get(route('admin.meetreqs'))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->has('requests', 1));

        // الإجراءات: (ب) ممنوع من إلغاء دعوة (أ)
        $this->actingAs($senderB)->post(route('employee.meetreqs.cancel', $req))->assertForbidden();
        $this->assertDatabaseHas('meet_requests', ['id' => $req->id]);

        // (أ) مسموح له بإلغاء دعوته — «أُلغيت» سجلاً تاريخياً (لا حذف صلب يُخفي الأثر عن العميل)
        $this->actingAs($senderA)->post(route('employee.meetreqs.cancel', $req))->assertRedirect();
        $this->assertSame(MeetRequest::STAGE_CANCELLED, $req->fresh()->stage);
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
