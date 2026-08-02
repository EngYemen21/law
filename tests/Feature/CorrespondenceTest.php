<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correspondence;
use App\Models\Execution;
use App\Models\User;
use App\Support\CorrespondenceFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نظام المخاطبات الرسميّة: دورة الحياة (إنشاء→مراجعة→اعتماد+إرسال→استقبال→إفادة→إغلاق)،
 * حراسة الأدوار، عزل الفرع/العميل، شاشة العميل، وربط ملفّ التنفيذ.
 */
class CorrespondenceTest extends TestCase
{
    use RefreshDatabase;

    private function make(User $lawyer, User $client): Correspondence
    {
        return CorrespondenceFlow::create($lawyer, $client, ['entity' => 'المحكمة التجارية', 'subject' => 'مذكرة', 'body' => 'نصّ']);
    }

    public function test_full_lifecycle_advances_through_stages(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الاختبار']);
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $corr = $this->make($lawyer, $client);
        $this->assertSame(0, $corr->stage);

        // المحامي يراجع (0→1→2)
        $this->actingAs($lawyer)->post(route('lawyer.correspondences.advance', $corr))->assertRedirect();
        $this->actingAs($lawyer)->post(route('lawyer.correspondences.advance', $corr))->assertRedirect();
        $this->assertSame(2, $corr->fresh()->stage);

        // المحامي لا يعتمد/يرسل من المرحلة 2 (بيد الإدارة)
        $this->actingAs($lawyer)->post(route('lawyer.correspondences.advance', $corr))->assertForbidden();

        // الإدارة تعتمد وترسل (2→3) → يولّد مرجعاً خارجيّاً
        $this->actingAs($admin)->post(route('admin.correspondences.advance', $corr))->assertRedirect();
        $corr->refresh();
        $this->assertSame(3, $corr->stage);
        $this->assertNotEmpty($corr->ext_ref);
        $this->assertSame('تم الإرسال', $corr->ext_status);

        // مزامنة الحالة الخارجيّة
        $this->actingAs($lawyer)->post(route('lawyer.correspondences.sync', $corr))->assertRedirect();

        // استقبال الردّ (→5)
        $this->actingAs($lawyer)->post(route('lawyer.correspondences.receive', $corr))->assertRedirect();
        $corr->refresh();
        $this->assertSame(5, $corr->stage);
        $this->assertNotEmpty($corr->reply_body);

        // إفادة العميل
        $this->actingAs($lawyer)->post(route('lawyer.correspondences.brief', $corr), ['note' => 'نُفيدكم بالموافقة'])->assertRedirect();
        $this->assertTrue($corr->fresh()->briefed);

        // الإغلاق (إدارة)
        $this->actingAs($admin)->post(route('admin.correspondences.close', $corr))->assertRedirect();
        $this->assertSame(6, $corr->fresh()->stage);
    }

    public function test_close_requires_admin_and_reply(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الاختبار']);
        $client = User::factory()->create(['role' => Role::Client]);
        $corr = $this->make($lawyer, $client);

        // المحامي لا يغلق
        $this->actingAs($lawyer)->post(route('lawyer.correspondences.close', $corr))->assertForbidden();
        // الإدارة لا تغلق قبل ورود الردّ (المرحلة < 5)
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->from('/admin/correspondences')->post(route('admin.correspondences.close', $corr))->assertSessionHasErrors('stage');
    }

    public function test_branch_isolation_blocks_other_lawyer(): void
    {
        $mine = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع أ']);
        $other = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع ب']);
        $client = User::factory()->create(['role' => Role::Client]);
        $corr = $this->make($mine, $client); // فرع أ

        $this->actingAs($other)->get(route('lawyer.correspondences.show', $corr))->assertForbidden();
        $this->actingAs($other)->post(route('lawyer.correspondences.advance', $corr))->assertForbidden();

        $this->actingAs($mine)->get(route('lawyer.correspondences'))
            ->assertInertia(fn ($p) => $p->component('correspondences')->has('corrs', 1));
        $this->actingAs($other)->get(route('lawyer.correspondences'))
            ->assertInertia(fn ($p) => $p->has('corrs', 0));
    }

    public function test_client_sees_own_and_requests_brief(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الاختبار']);
        $me = User::factory()->create(['role' => Role::Client]);
        $other = User::factory()->create(['role' => Role::Client]);
        $mine = $this->make($lawyer, $me);
        $this->make($lawyer, $other);

        $this->actingAs($me)->get(route('mycorr'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('mycorr')->has('corrs', 1)->where('corrs.0.id', $mine->number));

        // طلب إفادة رسميّة
        $this->actingAs($me)->post(route('correspondences.request-brief', $mine))->assertRedirect();
        $this->assertTrue($mine->fresh()->brief_requested);
        // عميل آخر لا يطلب على مخاطبة ليست له
        $this->actingAs($other)->post(route('correspondences.request-brief', $mine))->assertForbidden();
    }

    public function test_exec_request_corr_creates_linked_correspondence(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الاختبار']);
        // تنفيذ بمرحلة قيد التنفيذ مسند للمحامي
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EXE-C', 'subject' => 'تنفيذ حكم', 'status' => 'قيد التنفيذ', 'tone' => 'b-green', 'stage' => 8, 'exec_no' => '77-2026-تنفيذ', 'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name]);

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), ['action' => 'requestCorr'])->assertRedirect();

        $corr = Correspondence::where('execution_id', $exec->id)->first();
        $this->assertNotNull($corr);
        $this->assertSame('محكمة التنفيذ', $corr->entity);

        // تظهر في linkedCorr للتنفيذ (بطاقة المخاطبات المرتبطة)
        $exec->load('correspondences');
        $card = $exec->toFlowCard(true);
        $this->assertCount(1, $card['linkedCorr']);
        $this->assertSame($corr->number, $card['linkedCorr'][0]['id']);
    }
}
