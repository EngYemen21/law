<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قناة العميل لطلب إعادة الجدولة (فحص الأزرار — النواقص):
 * استشارته الفائتة كانت تعرض نصاً ميتاً «تواصل مع المكتب لإعادة الجدولة» بلا أي زرّ
 * ولا نقطة خادمية، واجتماعه القادم بلا أي قناة لطلب تغيير الموعد.
 */
class ClientRescheduleRequestTest extends TestCase
{
    use RefreshDatabase;

    private function missedConsult(User $client, User $lawyer): Consult
    {
        return Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-RR-1', 'subject' => 'نزاع تجاري',
            'channel' => 'مرئية', 'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'day' => now()->subDay()->format('Y-m-d'), 'time' => '10:00',
            'starts_at' => now()->subDay()->setTime(10, 0),
            'status' => 'مؤكد', 'session' => 'بانتظار الجلسة', 'paid_at' => now()->subDays(2),
        ]);
    }

    // ————— الاستشارة الفائتة —————

    public function test_client_can_request_consult_reschedule(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->missedConsult($client, $lawyer);

        $this->actingAs($client)->post(route('consults.reschedule-request', $consult))
            ->assertRedirect()->assertSessionHas('flash');

        // وُثّق بسجل التدقيق وأُشعر المحامي المسند والإدارة
        $audit = collect($consult->fresh()->audit ?? []);
        $this->assertTrue($audit->contains(fn ($a) => str_contains(json_encode($a, JSON_UNESCAPED_UNICODE), 'إعادة الجدولة')), 'طلب العميل موثَّق');
        $this->assertGreaterThan(0, UserNotification::where('user_id', $lawyer->id)->count(), 'المحامي أُشعر');
        $this->assertGreaterThan(0, UserNotification::where('user_id', $admin->id)->count(), 'الإدارة أُشعرت');
    }

    /** استشارة لم يفت موعدها لا تقبل الطلب — القناة للفائتة/«لم يحضر» فقط. */
    public function test_upcoming_consult_rejects_reschedule_request(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->missedConsult($client, $lawyer);
        $consult->update(['starts_at' => now()->addDays(2), 'day' => now()->addDays(2)->format('Y-m-d')]);

        $this->actingAs($client)->post(route('consults.reschedule-request', $consult))->assertStatus(422);
    }

    /** غير المالك يُصدّ. */
    public function test_foreign_client_cannot_request_consult_reschedule(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $intruder = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->missedConsult($client, $lawyer);

        $this->actingAs($intruder)->post(route('consults.reschedule-request', $consult))->assertStatus(403);
    }

    // ————— الاجتماع القادم —————

    public function test_client_can_request_meeting_time_change(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-RR-1', 'title' => 'اجتماع متابعة',
            'when_label' => 'غداً · 10:00', 'starts_at' => now()->addDay()->setTime(10, 0),
            'status' => 'قادم', 'assigned_lawyer_id' => $lawyer->id, 'created_by' => 'الموظف',
        ]);

        $this->actingAs($client)->post(route('meetings.change-request', $meeting))
            ->assertRedirect()->assertSessionHas('flash');

        $this->assertGreaterThan(0, UserNotification::where('user_id', $lawyer->id)->count(), 'المحامي المسند أُشعر');
        $this->assertGreaterThan(0, UserNotification::where('user_id', $admin->id)->count(), 'الإدارة أُشعرت');
    }

    /** اجتماع منتهٍ لا يقبل طلب تغيير الموعد. */
    public function test_ended_meeting_rejects_change_request(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $meeting = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-RR-2', 'title' => 'منتهٍ',
            'when_label' => 'أمس', 'status' => 'منتهٍ',
        ]);

        $this->actingAs($client)->post(route('meetings.change-request', $meeting))->assertStatus(422);
    }
}
