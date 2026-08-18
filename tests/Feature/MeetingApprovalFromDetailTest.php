<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\MeetingStatusBroadcast;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * اعتماد الإدارة من صفحة التفاصيل (الدفعة 2): زر الاعتماد أُتيح في MeetingDetailPage
 * على نفس نقطة admin.meetings.approve — يرفع مرحلة الدعوة ويوصل المحضر للعميل ويبثّ.
 */
class MeetingApprovalFromDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_approval_raises_stage_and_exposes_minutes(): void
    {
        Event::fake([MeetingStatusBroadcast::class]);
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-7600', 'title' => 'اجتماع مراجعة',
            'when_label' => 'أمس · 11:00', 'status' => 'منتهٍ',
            'minutes' => 'محضر نهائي', 'summary' => 'ملخص نهائي',
        ]);
        $req = MeetRequest::create([
            'user_id' => $client->id, 'meeting_id' => $meeting->id, 'ref' => 'MR-7600',
            'service' => 'مراجعة', 'type' => 'استشارة مرئية', 'day' => 'أمس', 'time' => '11:00',
            'sent_by' => 'المكتب', 'stage' => MeetRequest::STAGE_EXECUTED,
        ]);

        // قبل الاعتماد: بطاقة العميل بلا محضر وبلا شارة اعتماد
        $this->assertNull($meeting->toCard()['minutes']);
        $this->assertFalse($meeting->toCard()['approved']);

        $this->actingAs($admin)->post(route('admin.meetings.approve', $meeting))->assertRedirect();

        $meeting->refresh();
        $this->assertSame('معتمد', $meeting->approve);
        $this->assertSame(MeetRequest::STAGE_APPROVED, $req->fresh()->stage);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
        Event::assertDispatched(MeetingStatusBroadcast::class);

        $card = $meeting->toCard();
        $this->assertTrue($card['approved']);
        $this->assertSame('محضر نهائي', $card['minutes']);
    }

    public function test_approval_is_idempotent(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-7601', 'title' => 'اجتماع',
            'when_label' => 'أمس', 'status' => 'منتهٍ', 'approve' => 'معتمد',
        ]);

        $this->actingAs($admin)->post(route('admin.meetings.approve', $meeting))->assertRedirect();

        // معتمد أصلاً — لا إشعار مكرّر للعميل
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count());
    }

    public function test_non_admin_cannot_reach_admin_approval_route(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $meeting = Meeting::create(['ref' => 'M-7602', 'title' => 'اجتماع', 'when_label' => 'أمس', 'status' => 'منتهٍ']);

        // حارس الدور يعيد التوجيه بعيداً عن مسار الإدارة — لا يمرّ الاعتماد
        $this->actingAs($lawyer)->post(route('admin.meetings.approve', $meeting));
        $this->assertNotSame('معتمد', $meeting->fresh()->approve);
    }
}
