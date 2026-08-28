<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * لا تأكيد حضور من العميل — الموافقة تلد الدعوة مؤكَّدة مباشرة.
 *
 * تحديث 2026-08-25 (قرار صاحب المنتج): دعوة الموظف/المحامي تمرّ بموافقة الإدارة قبل
 * النشر — MeetInvitation::schedule يناديه مسار الموافقة (أو الإرسال للإدارة) لا إرسال
 * الموظف. بوّابة الموافقة نفسها مغطّاة في MeetInvitationApprovalGateTest.
 */
class MeetInvitationConfirmedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function staff(): User
    {
        $u = User::factory()->create(['role' => Role::Employee]);
        $u->syncPermissions(Permission::whereIn('name', ['إرسال دعوات الاجتماعات', 'جدولة المواعيد'])->get());

        return $u;
    }

    private function send(User $staff, User $client, User $lawyer, string $time = '11:00'): TestResponse
    {
        return $this->actingAs($staff)->post(route('employee.meetreqs.store'), [
            'client_id' => $client->id,
            'lawyer_id' => $lawyer->id,
            'service' => 'نزاع تجاري',
            'type' => 'استشارة مرئية',
            'day' => now()->addDays(3)->format('Y-m-d'),
            'time' => $time,
            'duration' => 60,
        ]);
    }

    /** موافقة الإدارة تُنشئ اجتماعاً «قادماً» وترفع الدعوة إلى مؤكَّدة — بلا أي إجراء من العميل. */
    public function test_approval_creates_a_confirmed_meeting(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->send($this->staff(), $client, $lawyer)->assertRedirect();
        $this->actingAs($admin)->post(route('admin.meetreqs.approve', MeetRequest::firstOrFail()))->assertRedirect();

        $req = MeetRequest::firstOrFail();
        $this->assertSame(MeetRequest::STAGE_CONFIRMED, $req->stage, 'الموافقة لم تلد الدعوة مؤكَّدة.');
        $this->assertNotNull($req->meeting_id, 'لم يُنشأ اجتماع — الدعوة معلّقة بلا اجتماع.');

        $meeting = Meeting::findOrFail($req->meeting_id);
        $this->assertSame('قادم', $meeting->status);
        $this->assertSame($client->id, $meeting->user_id);
    }

    /** وبعد الموافقة يراه العميل في «الاجتماعات» بلا أي إجراء منه (لا تأكيد حضور). */
    public function test_the_client_sees_it_without_doing_anything(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->send($this->staff(), $client, $lawyer)->assertRedirect();
        $this->actingAs($admin)->post(route('admin.meetreqs.approve', MeetRequest::firstOrFail()))->assertRedirect();

        $this->actingAs($client)->get(route('meetings'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('meetings', 1));
    }

    /**
     * ⚠️ حارس الانحدار الأهمّ: حارس الحجز المزدوج كان يرشّح `stage < CONFIRMED`. وبعد أن
     * صارت الدعوة تُولَد عند CONFIRMED توقّف الشرط عن مطابقة أي شيء **وصمت الحارس تماماً**.
     */
    public function test_the_double_booking_guard_did_not_go_silent(): void
    {
        $staff = $this->staff();
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $a = User::factory()->create(['role' => Role::Client]);
        $b = User::factory()->create(['role' => Role::Client]);

        $this->send($staff, $a, $lawyer, '11:00')->assertRedirect();

        // نفس المحامي ونفس الفترة لعميل آخر ⇒ يجب أن يُرفض
        $this->send($staff, $b, $lawyer, '11:00')->assertSessionHasErrors('time');

        $this->assertSame(1, MeetRequest::count(), 'وقع حجز مزدوج — الحارس صامت.');
    }

    /** المسار القديم يُحوّل ولا يموت (بريد الدعوة المُرسل سابقاً يشير إليه). */
    public function test_the_old_client_route_redirects(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->get('/meetreqs')->assertRedirect(route('meetings'));
    }

    /** ولا مسار تأكيد بعد الآن. */
    public function test_the_confirm_route_is_gone(): void
    {
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('meetreqs.confirm'),
            'مسار تأكيد الحضور ما زال موجوداً.'
        );
    }
}
