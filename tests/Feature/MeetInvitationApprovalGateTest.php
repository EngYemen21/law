<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * بوّابة نشر دعوات الاجتماعات (قرار صاحب المنتج 2026-08-25):
 * دعوة الموظف/المحامي تمرّ بموافقة الإدارة العليا قبل النشر — لا جلسة Zoom ولا اجتماع
 * ولا إشعار للعميل حتى الموافقة. دعوة الإدارة تُنشر فوراً (موافقتها ضمنية).
 *
 * كذلك اعتماد المحضر/الملخص: بعد انتهاء الاجتماع وتوليد مخرجاته فقط.
 */
class MeetInvitationApprovalGateTest extends TestCase
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

    private function send(User $actor, User $client, User $lawyer, string $routePrefix = 'employee'): TestResponse
    {
        return $this->actingAs($actor)->post(route($routePrefix.'.meetreqs.store'), [
            'client_id' => $client->id,
            'lawyer_id' => $lawyer->id,
            'service' => 'نزاع تجاري',
            'type' => 'استشارة مرئية',
            'day' => now()->addDays(3)->format('Y-m-d'),
            'time' => '11:00',
            'duration' => 60,
        ]);
    }

    // ————— البوّابة: دعوة الموظف معلّقة حتى موافقة الإدارة —————

    /** إرسال الموظف لا ينشر شيئاً: لا اجتماع ولا إشعار للعميل — والدعوة SENT. */
    public function test_staff_invitation_awaits_admin_approval(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->send($this->staff(), $client, $lawyer)->assertRedirect();

        $req = MeetRequest::firstOrFail();
        $this->assertSame(MeetRequest::STAGE_SENT, $req->stage, 'الدعوة يجب أن تبقى معلّقة بانتظار الإدارة');
        $this->assertNull($req->meeting_id, 'لا يُنشأ اجتماع قبل الموافقة');
        $this->assertSame(0, Meeting::count());
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count(), 'لا إشعار للعميل قبل الموافقة');
        $this->assertSame(1, UserNotification::where('user_id', $admin->id)->count(), 'الإدارة تُشعَر بالدعوة المعلّقة');
    }

    /** والعميل لا يرى شيئاً في «الاجتماعات» قبل الموافقة. */
    public function test_client_sees_nothing_before_approval(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $this->send($this->staff(), $client, $lawyer)->assertRedirect();

        $this->actingAs($client)->get(route('meetings'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('meetings', 0));
    }

    /** موافقة الإدارة تنشر: اجتماع «قادم» + CONFIRMED + إشعار العميل والمُرسِل. */
    public function test_admin_approval_publishes_the_invitation(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $staff = $this->staff();

        $this->send($staff, $client, $lawyer)->assertRedirect();
        $req = MeetRequest::firstOrFail();

        $this->actingAs($admin)->post(route('admin.meetreqs.approve', $req))->assertRedirect();

        $req->refresh();
        $this->assertSame(MeetRequest::STAGE_CONFIRMED, $req->stage);
        $this->assertNotNull($req->meeting_id);
        $this->assertSame('قادم', Meeting::findOrFail($req->meeting_id)->status);
        $this->assertGreaterThan(0, UserNotification::where('user_id', $client->id)->count(), 'العميل يُشعَر بعد الموافقة');
        $this->assertGreaterThan(0, UserNotification::where('user_id', $staff->id)->count(), 'المُرسِل يُشعَر بالموافقة');

        $this->actingAs($client)->get(route('meetings'))
            ->assertInertia(fn ($p) => $p->has('meetings', 1));
    }

    /** دعوة الإدارة نفسها تُنشر فوراً — موافقتها ضمنية. */
    public function test_admin_invitation_publishes_immediately(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->send($admin, $client, $lawyer, 'admin')->assertRedirect();

        $req = MeetRequest::firstOrFail();
        $this->assertSame(MeetRequest::STAGE_CONFIRMED, $req->stage);
        $this->assertNotNull($req->meeting_id);
    }

    /** الموافقة على دعوة غير معلّقة تُرفض (لا موافقة مزدوجة). */
    public function test_approving_a_non_pending_invitation_fails(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $req = MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-GATE-1', 'service' => 'متابعة', 'type' => 'استشارة مرئية',
            'day' => now()->addDay()->format('Y-m-d'), 'time' => '10:00', 'sent_by' => 'موظف',
            'stage' => MeetRequest::STAGE_CONFIRMED,
        ]);

        $this->actingAs($admin)->post(route('admin.meetreqs.approve', $req))->assertStatus(422);
    }

    /** المحامي/الموظف لا يبلغ مسار الموافقة أصلاً (المسار في مجموعة الإدارة). */
    public function test_staff_cannot_reach_the_approval_route(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $req = MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-GATE-2', 'service' => 'متابعة', 'type' => 'استشارة مرئية',
            'day' => now()->addDay()->format('Y-m-d'), 'time' => '10:00', 'sent_by' => 'موظف',
        ]);

        // EnsureRole يحوّل زيارات المتصفح برسالة (وXHR وحده يتلقى 403) — الفيصل: لا موافقة تقع
        $this->actingAs($this->staff())->post(route('admin.meetreqs.approve', $req))->assertRedirect();
        $this->assertSame(MeetRequest::STAGE_SENT, $req->fresh()->stage, 'الموظف لا يستطيع الموافقة على دعوة');
        $this->assertNull($req->fresh()->meeting_id);
    }

    /** إعادة إرسال الموظف لدعوة منتهية تعود للبوّابة (SENT) بلا إشعار للعميل. */
    public function test_staff_resend_returns_to_the_gate(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $staff = $this->staff();
        $req = MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-GATE-3', 'service' => 'متابعة', 'type' => 'استشارة مرئية',
            'day' => now()->subDay()->format('Y-m-d'), 'time' => '10:00',
            'sent_by' => $staff->name, 'sent_by_id' => $staff->id,
            'stage' => MeetRequest::STAGE_EXPIRED,
        ]);

        $this->actingAs($staff)->post(route('employee.meetreqs.resend', $req), [
            'day' => now()->addDays(2)->format('Y-m-d'), 'time' => '13:00',
        ])->assertRedirect();

        $this->assertSame(MeetRequest::STAGE_SENT, $req->fresh()->stage);
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count());
    }

    // ————— بوّابة اعتماد المحضر/الملخص —————

    /** لا اعتماد لاجتماع لم ينته بعد — حتى لو وُجد ملخص. */
    public function test_cannot_approve_minutes_before_the_meeting_ends(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = Meeting::create([
            'ref' => 'M-GATE-1', 'title' => 'اجتماع قادم', 'when_label' => 'غداً · 10:00',
            'status' => 'قادم', 'summary' => 'ملخص مبكر',
        ]);

        $this->actingAs($admin)->post(route('admin.meetings.approve', $meeting))->assertStatus(422);
        $this->assertNotSame('معتمد', $meeting->fresh()->approve);
    }

    /** ولا اعتماد لاجتماع منتهٍ بلا أي مخرجات (لم يصل ملخص Zoom بعد). */
    public function test_cannot_approve_an_ended_meeting_without_outputs(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = Meeting::create([
            'ref' => 'M-GATE-2', 'title' => 'اجتماع منتهٍ بلا مخرجات', 'when_label' => 'أمس · 10:00',
            'status' => 'منتهٍ',
        ]);

        $this->actingAs($admin)->post(route('admin.meetings.approve', $meeting))->assertStatus(422);
    }

    /**
     * مصدر المخرجات محايد: الإدخال اليدوي للمحضر بعد الانتهاء يكافئ تلخيص Zoom —
     * اجتماع منتهٍ بلا أي مخرجات، تكتب الإدارة المحضر يدوياً، فيمرّ الاعتماد.
     */
    public function test_manual_entry_after_end_enables_approval(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = Meeting::create([
            'ref' => 'M-GATE-4', 'title' => 'اجتماع منتهٍ — إدخال يدوي', 'when_label' => 'أمس · 10:00',
            'status' => 'منتهٍ',
        ]);

        // قبل الإدخال اليدوي: الاعتماد مرفوض
        $this->actingAs($admin)->post(route('admin.meetings.approve', $meeting))->assertStatus(422);

        // الإدارة تكتب المحضر يدوياً (نفس مسار زرّ «حفظ المحضر»)
        $this->actingAs($admin)->post(route('admin.meetings.minutes', $meeting), [
            'minutes' => 'محضر مكتوب يدوياً: نوقشت البنود واتُّخذت القرارات.',
        ])->assertRedirect();

        // بعده: الاعتماد يمرّ
        $this->actingAs($admin)->post(route('admin.meetings.approve', $meeting))->assertRedirect();
        $this->assertSame('معتمد', $meeting->fresh()->approve);
    }

    /** الاعتماد يمرّ بعد الانتهاء وتوفّر الملخص/المحضر. */
    public function test_approval_passes_after_end_with_outputs(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = Meeting::create([
            'ref' => 'M-GATE-3', 'title' => 'اجتماع منتهٍ بمخرجات', 'when_label' => 'أمس · 10:00',
            'status' => 'منتهٍ', 'summary' => 'ملخص Zoom المولَّد',
        ]);

        $this->actingAs($admin)->post(route('admin.meetings.approve', $meeting))->assertRedirect();
        $this->assertSame('معتمد', $meeting->fresh()->approve);
    }
}
